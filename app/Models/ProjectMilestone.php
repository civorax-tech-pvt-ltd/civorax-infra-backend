<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['project_id', 'title', 'phase', 'sequence', 'target_date', 'completed_at', 'billing_percent', 'status'])]
class ProjectMilestone extends Model
{
    use SoftDeletes;

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

        static::saved(fn (ProjectMilestone $milestone) => $milestone->refreshProgress());
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

        $this->project?->refreshProgress();
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
}
