<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A naike / petty contractor who brings a group of labourers and may be paid on their behalf.
 */
#[Fillable(['name', 'phone', 'address', 'pan_no', 'notes', 'created_by'])]
class LabourContractor extends Model
{
    use LogsActivity, SoftDeletes;

    public function labourers(): HasMany
    {
        return $this->hasMany(Labourer::class);
    }

    public function wagePayments(): HasMany
    {
        return $this->hasMany(WagePayment::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
