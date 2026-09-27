<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable([
    'user_id', 'fullname', 'dob', 'contact1', 'contact2', 'designation',
    'marital_status', 'national_id_path', 'bank_name', 'bank_account_name',
    'bank_account_number', 'created_by',
])]
#[Hidden(['national_id_path', 'bank_account_number'])]
class TeamMember extends Model
{
    use LogsActivity, SoftDeletes;

    protected function casts(): array
    {
        return ['dob' => 'date'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnlyDirty();
    }

    /**
     * Team members who are not super admins, i.e. staff that other staff may assign work to.
     */
    public function scopeWithoutSuperAdmins(Builder $query): void
    {
        $query->whereDoesntHave('user.roles', fn (Builder $query) => $query->where('name', 'super_admin'));
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function locationPings(): HasMany
    {
        return $this->hasMany(LocationPing::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_team');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'assignee_id');
    }

    public function assistedTasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'task_members');
    }
}
