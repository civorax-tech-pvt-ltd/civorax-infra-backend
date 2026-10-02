<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Key material received on site, recorded by the supervisor with the challan photo.
 */
#[Fillable(['project_id', 'key_material_id', 'vendor_id', 'delivered_on', 'quantity', 'challan_no', 'photos', 'note', 'entered_by'])]
class MaterialDelivery extends Model
{
    use LogsActivity;

    protected function casts(): array
    {
        return [
            'delivered_on' => 'date',
            'quantity' => 'decimal:2',
            'photos' => 'array',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(KeyMaterial::class, 'key_material_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class)->withTrashed();
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }
}
