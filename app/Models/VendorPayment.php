<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Money paid to a vendor, optionally against one bill; counts as cash paid out for its project.
 */
#[Fillable(['vendor_id', 'project_id', 'purchase_bill_id', 'amount', 'paid_on', 'method', 'reference', 'note', 'paid_by'])]
class VendorPayment extends Model
{
    use LogsActivity;

    protected static function booted(): void
    {
        // A payment against a bill belongs to that bill's project.
        static::saving(function (VendorPayment $payment): void {
            if ($payment->purchase_bill_id && ($bill = PurchaseBill::find($payment->purchase_bill_id))) {
                $payment->vendor_id = $bill->vendor_id;
                $payment->project_id = $bill->project_id;
            }
        });
    }

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

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class)->withTrashed();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(PurchaseBill::class, 'purchase_bill_id');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }
}
