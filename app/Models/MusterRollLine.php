<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * One labourer's row on a muster roll (Part I). Days maps the day number to P, H or A (+ overtime hours).
 */
#[Fillable([
    'muster_roll_id', 'labourer_id', 'work_type', 'days', 'present_days',
    'overtime_hours', 'wage_rate', 'total_wage', 'arrear_reason', 'remarks',
])]
class MusterRollLine extends Model
{
    use LogsActivity;

    protected function casts(): array
    {
        return [
            'days' => 'array',
            'present_days' => 'decimal:1',
            'overtime_hours' => 'decimal:1',
            'wage_rate' => 'decimal:2',
            'total_wage' => 'decimal:2',
        ];
    }

    public function musterRoll(): BelongsTo
    {
        return $this->belongsTo(MusterRoll::class);
    }

    public function labourer(): BelongsTo
    {
        return $this->belongsTo(Labourer::class)->withTrashed();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
