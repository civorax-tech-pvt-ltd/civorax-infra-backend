<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable(['name', 'address', 'latitude', 'longitude', 'geofence_radius', 'is_active'])]
class OfficeLocation extends Model
{
    use LogsActivity;

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'geofence_radius' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
