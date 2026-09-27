<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'address', 'latitude', 'longitude', 'geofence_radius', 'is_active'])]
class OfficeLocation extends Model
{
    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'geofence_radius' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
