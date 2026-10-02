<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Money paid to a labourer for one site: wages, or an advance that later wages absorb.
 * When paid through a naike, labour_contractor_id records who received the cash.
 */
#[Fillable([
    'project_id', 'labourer_id', 'labour_contractor_id', 'muster_roll_id', 'type',
    'amount', 'method', 'reference', 'paid_on', 'note', 'paid_by',
])]
class WagePayment extends Model
{
    use LogsActivity;

    /**
     * @var array<string, string>
     */
    public const TYPES = [
        'wage' => 'Wages',
        'advance' => 'Advance',
        'recovery' => 'Advance returned',
    ];

    protected static function booted(): void
    {
        // Money the labourer hands back is stored negative, so every balance is a plain SUM(amount).
        static::saving(function (WagePayment $payment): void {
            $payment->amount = $payment->type === 'recovery' ? -abs((float) $payment->amount) : abs((float) $payment->amount);
        });
    }

    /**
     * @var array<string, string>
     */
    public const METHODS = [
        'cash' => 'Cash',
        'bank' => 'Bank transfer',
        'wallet' => 'eSewa / Khalti',
        'cheque' => 'Cheque',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_on' => 'date',
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

    public function labourer(): BelongsTo
    {
        return $this->belongsTo(Labourer::class)->withTrashed();
    }

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(LabourContractor::class, 'labour_contractor_id')->withTrashed();
    }

    public function musterRoll(): BelongsTo
    {
        return $this->belongsTo(MusterRoll::class);
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }
}
