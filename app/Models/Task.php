<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable(['assignee_id', 'project_id', 'milestone_id', 'title', 'description', 'status', 'weight', 'due_at', 'created_by'])]
class Task extends Model
{
    use LogsActivity, SoftDeletes;

    /**
     * @var array<string, string>
     */
    public const STATUSES = [
        'pending' => 'Pending',
        'in_progress' => 'In Progress',
        'completed' => 'Completed',
        'blocked' => 'Blocked',
    ];

    protected static function booted(): void
    {
        static::saving(function (Task $task): void {
            if ($task->milestone_id !== null) {
                $task->project_id = ProjectMilestone::query()->whereKey($task->milestone_id)->value('project_id');
            }
        });

        static::saved(function (Task $task): void {
            $task->refreshMilestones($task->milestone_id, $task->getOriginal('milestone_id'));
        });

        static::deleted(fn (Task $task) => $task->refreshMilestones($task->milestone_id));
        static::restored(fn (Task $task) => $task->refreshMilestones($task->milestone_id));
    }

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'weight' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnlyDirty();
    }

    /**
     * Tasks a team member is assigned to or helping on.
     */
    public function scopeInvolving(Builder $query, TeamMember $teamMember): void
    {
        $query->where(fn (Builder $query) => $query
            ->where('assignee_id', $teamMember->getKey())
            ->orWhereHas('members', fn (Builder $query) => $query->whereKey($teamMember->getKey())));
    }

    public function scopeOverdue(Builder $query): void
    {
        $query->where('status', '!=', 'completed')->where('due_at', '<', now());
    }

    public function isOverdue(): bool
    {
        return $this->status !== 'completed' && $this->due_at?->isPast() === true;
    }

    /**
     * Recalculate every milestone this task belongs to, or belonged to before a move.
     */
    protected function refreshMilestones(?int ...$milestoneIds): void
    {
        ProjectMilestone::query()
            ->whereKey(array_unique(array_filter($milestoneIds)))
            ->get()
            ->each(fn (ProjectMilestone $milestone) => $milestone->refreshProgress());
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(TeamMember::class, 'assignee_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(ProjectMilestone::class, 'milestone_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(TeamMember::class, 'task_members');
    }
}
