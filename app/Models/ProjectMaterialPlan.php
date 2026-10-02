<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Planned quantity of a key material for a project (from the estimate).
 */
#[Fillable(['project_id', 'key_material_id', 'planned_quantity'])]
class ProjectMaterialPlan extends Model
{
    use LogsActivity;

    protected function casts(): array
    {
        return ['planned_quantity' => 'decimal:2'];
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(KeyMaterial::class, 'key_material_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
