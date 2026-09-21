<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['project_id', 'title', 'sequence', 'target_date', 'completed_at', 'billing_percent', 'status'])]
class ProjectMilestone extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'target_date' => 'date',
            'completed_at' => 'date',
            'billing_percent' => 'decimal:2',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'milestone_id');
    }
}
