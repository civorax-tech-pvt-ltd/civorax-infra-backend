<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable(['name', 'slug', 'description', 'is_active'])]
class ClientType extends Model
{
    use LogsActivity, SoftDeletes;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
