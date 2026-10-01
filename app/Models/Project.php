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
    'client_id', 'client_type', 'project_type_id', 'title', 'description', 'site_address',
    'city', 'ward_no', 'latitude', 'longitude', 'geofence_radius',
    'status', 'fee', 'price_basis', 'cost_to_finish_override', 'manual_progress', 'track_item_costs', 'share_boq_with_client',
    'start_date', 'estimated_end_date', 'created_by',
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
     * @var array<string, string>
     */
    public const CLIENT_TYPES = [
        'house_owner' => 'House owner',
        'company' => 'Company',
        'government' => 'Government',
    ];

    /**
     * Whether the contract price already includes VAT or VAT is added on top (for future VAT reporting).
     *
     * @var array<string, string>
     */
    public const PRICE_BASES = [
        'vat_inclusive' => 'Price includes VAT (final price)',
        'plus_vat' => 'Price plus VAT',
    ];

    /**
     * Statuses set by hand that automatic status updates never override.
     *
     * @var list<string>
     */
    public const MANUAL_STATUSES = ['inquiry', 'on_hold'];

    protected static function booted(): void
    {
        // While a quotation is accepted, it alone decides the fee.
        static::saving(function (Project $project): void {
            if ($project->exists && $project->isDirty('fee') && ($accepted = $project->acceptedQuotation())) {
                $project->fee = $accepted->total;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'fee' => 'decimal:2',
            'start_date' => 'date',
            'estimated_end_date' => 'date',
            'progress' => 'integer',
            'manual_progress' => 'integer',
            'track_item_costs' => 'boolean',
            'share_boq_with_client' => 'boolean',
            'cost_to_finish_override' => 'decimal:2',
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
     * Sites a user may record labour, muster rolls and site reports for:
     * all of them for super admins and site approvers, otherwise the team member's own projects.
     */
    public function scopeSiteAccessibleBy(Builder $query, ?User $user): void
    {
        if ($user?->hasSitePower('approve_site_records')) {
            return;
        }

        $teamMember = $user?->teamMember;

        $teamMember ? $query->visibleToTeamMember($teamMember) : $query->whereRaw('1 = 0');
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

    /**
     * What the client pays in total: the agreed fee (accepted quotation) plus approved extra work.
     */
    public function contractValue(): float
    {
        return round((float) $this->fee + (float) $this->variations()->approved()->sum('amount'), 2);
    }

    public function amountPaid(): float
    {
        return (float) $this->payments()->sum('amount');
    }

    public function balanceDue(): ?float
    {
        return $this->fee === null ? null : $this->contractValue() - $this->amountPaid();
    }

    /**
     * What the client owes today: the billing share of completed milestones minus payments so far.
     * Without billing percentages, the whole remaining balance counts as due.
     */
    public function amountDueNow(): ?float
    {
        if ($this->fee === null) {
            return null;
        }

        $milestones = $this->milestones()->get(['status', 'billing_percent']);

        if ($milestones->sum('billing_percent') <= 0) {
            return max(0, $this->balanceDue());
        }

        $earned = (float) $this->fee * $milestones->where('status', 'completed')->sum('billing_percent') / 100;

        return max(0, round($earned - $this->amountPaid(), 2));
    }

    /**
     * Spread everything paid across milestones in sequence: the first milestone's share is
     * filled first, any extra (overpayment or advance) rolls on to the next, and so on.
     *
     * @return array<int, float> milestone id => amount covered
     */
    public function milestoneAllocations(?Payment $ignore = null): array
    {
        if ($this->fee === null) {
            return [];
        }

        $remaining = $this->amountPaid() - ($ignore?->exists && $ignore->project_id === $this->id ? (float) $ignore->amount : 0);
        $allocations = [];

        foreach ($this->milestones()->orderBy('sequence')->orderBy('id')->get() as $milestone) {
            $share = $milestone->billing_percent > 0 ? round((float) $this->fee * (float) $milestone->billing_percent / 100, 2) : 0;
            $covered = (float) max(0, min($share, round($remaining, 2)));

            $allocations[$milestone->id] = $covered;
            $remaining -= $covered;
        }

        return $allocations;
    }

    public function acceptedQuotation(): ?Quotation
    {
        return $this->quotations()->where('status', 'accepted')->first();
    }

    public function pendingSubmissionsTotal(): float
    {
        return (float) $this->paymentSubmissions()->where('status', 'pending')->sum('amount');
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

    public function paymentSubmissions(): HasMany
    {
        return $this->hasMany(ProjectPaymentSubmission::class);
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class)->orderByDesc('version');
    }

    public function labourAttendances(): HasMany
    {
        return $this->hasMany(LabourAttendance::class);
    }

    public function musterRolls(): HasMany
    {
        return $this->hasMany(MusterRoll::class);
    }

    public function wagePayments(): HasMany
    {
        return $this->hasMany(WagePayment::class);
    }

    public function siteReports(): HasMany
    {
        return $this->hasMany(SiteReport::class);
    }

    public function boqItems(): HasMany
    {
        return $this->hasMany(BoqItem::class)->orderBy('sort')->orderBy('id');
    }

    public function budgets(): HasMany
    {
        return $this->hasMany(ProjectBudget::class);
    }

    public function costs(): HasMany
    {
        return $this->hasMany(ProjectCost::class);
    }

    public function purchaseBills(): HasMany
    {
        return $this->hasMany(PurchaseBill::class);
    }

    public function pettyCashClaims(): HasMany
    {
        return $this->hasMany(PettyCashClaim::class);
    }

    /**
     * The latest agreement / contract document shared on this project, if any.
     */
    public function agreement(): ?ProjectDocument
    {
        return $this->documents()->where('type', 'agreement')->latest('version')->latest('id')->first();
    }

    public function variations(): HasMany
    {
        return $this->hasMany(Variation::class);
    }

    public function staffCostAllocations(): HasMany
    {
        return $this->hasMany(StaffCostAllocation::class);
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    public function equipmentEntries(): HasMany
    {
        return $this->hasMany(EquipmentEntry::class);
    }

    public function materialPlans(): HasMany
    {
        return $this->hasMany(ProjectMaterialPlan::class);
    }

    public function itemCostReport(): BoqItemCostReport
    {
        return new BoqItemCostReport($this);
    }

    public function materialReport(): ProjectMaterialReport
    {
        return new ProjectMaterialReport($this);
    }

    public function costReport(): ProjectCostReport
    {
        return new ProjectCostReport($this);
    }

    /**
     * Value-weighted BOQ progress: Σ(executed × rate) ÷ Σ planned value; null when the project has no BOQ.
     */
    public function boqProgress(): ?float
    {
        $items = $this->boqItems()->with('latestApprovedMeasurement')->get();
        $planned = (float) $items->sum(fn (BoqItem $item): float => (float) $item->planned_value);

        return $planned > 0 ? round($items->sum(fn (BoqItem $item): float => $item->earnedValue()) / $planned * 100, 1) : null;
    }
}
