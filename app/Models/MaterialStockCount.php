<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Monthly count of what is left on site; used = received ± transfers − left.
 */
#[Fillable(['project_id', 'key_material_id', 'counted_on', 'quantity_left', 'note', 'entered_by'])]
class MaterialStockCount extends Model
{
    use LogsActivity;

    protected function casts(): array
    {
        return [
            'counted_on' => 'date',
            'quantity_left' => 'decimal:2',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnlyDirty();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(KeyMaterial::class, 'key_material_id');
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }
}
