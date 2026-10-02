<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable(['name', 'slug', 'description', 'is_active'])]
class ProjectType extends Model
{
    use LogsActivity, SoftDeletes;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function milestoneTemplates(): HasMany
    {
        return $this->hasMany(MilestoneTemplate::class)->orderBy('sequence');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
