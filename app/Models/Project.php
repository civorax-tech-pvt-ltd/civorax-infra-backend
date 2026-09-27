<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable([
    'client_id', 'project_type_id', 'title', 'description', 'site_address',
    'city', 'ward_no', 'status', 'fee', 'start_date', 'estimated_end_date', 'created_by',
])]
class Project extends Model
{
    use LogsActivity, SoftDeletes;

    /**
     * @var array<string, string>
     */
    public const STATUSES = [
        'inquiry' => 'Inquiry',
        'planning' => 'Planning',
        'designing' => 'Designing',
        'awaiting_approval' => 'Awaiting Approval',
        'execution' => 'Execution',
        'completed' => 'Completed',
        'on_hold' => 'On Hold',
    ];

    /**
     * Statuses a milestone can represent; the project status follows its first unfinished milestone.
     *
     * @var array<string, string>
     */
    public const PHASES = [
        'planning' => 'Planning',
        'designing' => 'Designing',
        'awaiting_approval' => 'Awaiting Approval',
        'execution' => 'Execution',
    ];

    /**
     * Statuses set by hand that automatic status updates never override.
     *
     * @var list<string>
     */
    public const MANUAL_STATUSES = ['inquiry', 'on_hold'];

    protected function casts(): array
    {
        return [
            'fee' => 'decimal:2',
            'start_date' => 'date',
            'estimated_end_date' => 'date',
            'progress' => 'integer',
        ];
    }

    /**
     * Projects a team member works on: assigned to the project, or assigned/helping on one of its tasks.
     */
    public function scopeVisibleToTeamMember(Builder $query, TeamMember $teamMember): void
    {
        $query->where(fn (Builder $query) => $query
            ->whereHas('teamMembers', fn (Builder $query) => $query->whereKey($teamMember->getKey()))
            ->orWhereHas('tasks', fn (Builder $query) => $query->involving($teamMember)));
    }

    /**
     * Projects whose work is still going on.
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', array_keys(self::PHASES));
    }

    /**
     * The description as HTML; older plain-text descriptions keep their line breaks.
     */
    public function descriptionHtml(): string
    {
        $description = (string) $this->description;

        return $description === strip_tags($description) ? nl2br(e($description)) : $description;
    }

    public function amountPaid(): float
    {
        return (float) $this->payments()->sum('amount');
    }

    public function balanceDue(): ?float
    {
        return $this->fee === null ? null : (float) $this->fee - $this->amountPaid();
    }

    /**
     * The progress the project should have reached by today if work ran evenly from start to estimated end.
     */
    public function expectedProgress(): ?int
    {
        $start = $this->start_date ?? $this->created_at;

        if ($start === null || $this->estimated_end_date === null || $this->estimated_end_date->lte($start)) {
            return null;
        }

        $start = $start->copy()->startOfDay();
        $elapsed = $start->diffInDays(today(), false) / $start->diffInDays($this->estimated_end_date);

        return (int) round(max(0, min(1, $elapsed)) * 100);
    }

    public function isBehindSchedule(): bool
    {
        $expected = $this->expectedProgress();

        return $expected !== null && $this->progress < $expected;
    }

    /**
     * Recalculate overall progress from milestones (weighted by billing % when every
     * milestone has one) and move the status to the phase of the first unfinished milestone.
     */
    public function refreshProgress(): void
    {
        $milestones = $this->milestones()->orderBy('sequence')->orderBy('id')->get();

        if ($milestones->isEmpty()) {
            $this->forceFill(['progress' => 0])->save();

            return;
        }

        $weighByBilling = $milestones->every(fn (ProjectMilestone $milestone): bool => $milestone->billing_percent > 0);
        $weight = fn (ProjectMilestone $milestone): float => $weighByBilling ? (float) $milestone->billing_percent : 1.0;

        $this->progress = (int) round(
            $milestones->sum(fn (ProjectMilestone $milestone): float => $weight($milestone) * $milestone->progress)
            / $milestones->sum($weight)
        );

        if (! in_array($this->status, self::MANUAL_STATUSES, true)) {
            $nextMilestone = $milestones->first(fn (ProjectMilestone $milestone): bool => $milestone->status !== 'completed');

            if ($nextMilestone === null) {
                $this->status = 'completed';
            } elseif ($nextMilestone->phase !== null) {
                $this->status = $nextMilestone->phase;
            }
        }

        $this->save();
    }

    /**
     * Create milestones and their tasks from the project type's templates.
     */
    public function applyMilestoneTemplates(): int
    {
        $templates = $this->projectType?->milestoneTemplates()->with('tasks')->get() ?? collect();

        foreach ($templates as $template) {
            $milestone = $this->milestones()->create([
                'title' => $template->title,
                'phase' => $template->phase,
                'sequence' => $template->sequence,
                'billing_percent' => $template->billing_percent,
                'status' => 'pending',
            ]);

            foreach ($template->tasks as $templateTask) {
                $milestone->tasks()->create([
                    'project_id' => $this->id,
                    'title' => $templateTask->title,
                    'weight' => $templateTask->weight,
                    'status' => 'pending',
                    'created_by' => $this->created_by,
                ]);
            }
        }

        return $templates->count();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnlyDirty();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function projectType(): BelongsTo
    {
        return $this->belongsTo(ProjectType::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function teamMembers(): BelongsToMany
    {
        return $this->belongsToMany(TeamMember::class, 'project_team');
    }

    public function vendors(): BelongsToMany
    {
        return $this->belongsToMany(Vendor::class, 'project_vendors')
            ->withPivot('scope');
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(ProjectMilestone::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ProjectDocument::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class)->orderByDesc('version');
    }
}
