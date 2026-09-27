<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['project_type_id', 'title', 'phase', 'sequence', 'billing_percent'])]
class MilestoneTemplate extends Model
{
    protected function casts(): array
    {
        return ['billing_percent' => 'decimal:2'];
    }

    public function projectType(): BelongsTo
    {
        return $this->belongsTo(ProjectType::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(MilestoneTemplateTask::class)->orderBy('sort');
    }
}
