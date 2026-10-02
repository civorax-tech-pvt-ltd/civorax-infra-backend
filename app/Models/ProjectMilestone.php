<?php

namespace App\Models;

use App\Notifications\Alerts;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable(['project_id', 'title', 'phase', 'sequence', 'target_date', 'completed_at', 'billing_percent', 'status'])]
class ProjectMilestone extends Model
{
    use LogsActivity, SoftDeletes;

    /**
     * @var array<string, string>
     */
    public const STATUSES = [
        'pending' => 'Pending',
        'in_progress' => 'In Progress',
        'completed' => 'Completed',
    ];

    protected static function booted(): void
    {
        static::saving(function (ProjectMilestone $milestone): void {
            if ($milestone->status === 'completed' && $milestone->completed_at === null) {
                $milestone->completed_at = now();
            }
        });

        static::saved(function (ProjectMilestone $milestone): void {
            $justCompleted = $milestone->status === 'completed' && $milestone->wasChanged('status');

            $milestone->refreshProgress();

            if ($justCompleted) {
                Alerts::milestoneCompleted($milestone);
            }
        });
        static::deleted(fn (ProjectMilestone $milestone) => $milestone->project?->refreshProgress());
        static::restored(fn (ProjectMilestone $milestone) => $milestone->project?->refreshProgress());
    }

    protected function casts(): array
    {
        return [
            'target_date' => 'date',
            'completed_at' => 'date',
            'billing_percent' => 'decimal:2',
            'progress' => 'integer',
        ];
    }

    /**
     * Recalculate progress from task weights, start the milestone once work begins,
     * then roll up to the project. Completion itself is always confirmed by a person.
     */
    public function refreshProgress(): void
    {
        $progressBefore = (int) $this->progress;
        $tasks = $this->tasks()->get(['status', 'weight']);
        $totalWeight = $tasks->sum('weight');

        if ($this->status === 'completed') {
            $this->progress = 100;
        } elseif ($totalWeight > 0) {
            $this->progress = (int) round($tasks->where('status', 'completed')->sum('weight') / $totalWeight * 100);
        } else {
            $this->progress = 0;
        }

        if ($this->status === 'pending' && $tasks->whereIn('status', ['in_progress', 'completed'])->isNotEmpty()) {
            $this->status = 'in_progress';
        }

        $this->saveQuietly();

        if ($progressBefore < 100 && $this->isReadyToComplete()) {
            Alerts::milestoneReadyToComplete($this);
        }

        $this->project?->refreshProgress();
    }

    /**
     * This milestone's share of the project fee, or null while the fee or billing % is unknown.
     */
    public function billingAmount(): ?float
    {
        $fee = $this->project?->fee;

        if ($fee === null || ! ($this->billing_percent > 0)) {
            return null;
        }

        return round((float) $fee * (float) $this->billing_percent / 100, 2);
    }

    /**
     * How much of this milestone's share is covered, after the project's payments are applied
     * to milestones in sequence (so advances and overpayments count too).
     */
    public function amountPaid(?Payment $ignore = null): float
    {
        return $this->project?->milestoneAllocations($ignore)[$this->id] ?? 0.0;
    }

    public function amountLeft(?Payment $ignore = null): ?float
    {
        $billing = $this->billingAmount();

        return $billing === null ? null : max(0, round($billing - $this->amountPaid($ignore), 2));
    }

    /**
     * Unpaid, Part paid or Paid; null when the milestone has no billing amount.
     */
    public function paymentState(): ?string
    {
        $billing = $this->billingAmount();

        if ($billing === null) {
            return null;
        }

        $paid = $this->amountPaid();

        $state = match (true) {
            $paid <= 0 => 'Unpaid',
            $paid < $billing => 'Part paid',
            default => 'Paid',
        };

        return $paid > 0 && $this->status !== 'completed' ? "{$state} in advance" : $state;
    }

    public function isReadyToComplete(): bool
    {
        return $this->status !== 'completed' && $this->progress === 100;
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'milestone_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'milestone_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
