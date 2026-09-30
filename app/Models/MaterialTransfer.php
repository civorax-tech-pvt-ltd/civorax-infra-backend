<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Leftover material moved to another project at cost (cost leaves one project and enters the other),
 * or returned to the supplier (cost leaves the project).
 */
#[Fillable(['from_project_id', 'to_project_id', 'vendor_id', 'key_material_id', 'transferred_on', 'quantity', 'value', 'note', 'entered_by'])]
class MaterialTransfer extends Model
{
    use LogsActivity;

    protected static function booted(): void
    {
        static::saved(fn (MaterialTransfer $transfer) => ProjectCost::syncMaterialTransfer($transfer));
        static::deleted(fn (MaterialTransfer $transfer) => ProjectCost::syncMaterialTransfer($transfer, removed: true));
    }

    protected function casts(): array
    {
        return [
            'transferred_on' => 'date',
            'quantity' => 'decimal:2',
            'value' => 'decimal:2',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnlyDirty();
    }

    public function isReturn(): bool
    {
        return $this->to_project_id === null;
    }

    public function fromProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'from_project_id');
    }

    public function toProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'to_project_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class)->withTrashed();
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(KeyMaterial::class, 'key_material_id');
    }
}
