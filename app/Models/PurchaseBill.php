<?php

namespace App\Models;

use App\Notifications\Alerts;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A supplier bill for a project. Whether its VAT is claimable is computed (never typed) from the company
 * tax settings and the bill date, so registering for VAT later changes nothing already recorded.
 */
#[Fillable([
    'project_id', 'vendor_id', 'work_order_id', 'bill_type', 'bill_no', 'vendor_pan_vat', 'bill_date', 'category',
    'base_amount', 'vat_amount', 'billed_to_company', 'items', 'photos', 'description',
    'status', 'entered_by', 'approved_by', 'approved_at', 'review_note', 'boq_item_id',
])]
class PurchaseBill extends Model
{
    use LogsActivity;

    /**
     * @var array<string, string>
     */
    public const TYPES = [
        'vat' => 'VAT bill',
        'pan' => 'PAN bill',
    ];

    /**
     * @var array<string, string>
     */
    public const STATUSES = [
        'pending' => 'Pending',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
    ];

    /**
     * Defaults the model knows before saving (the flag and VAT rules run on these in the same save).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'bill_type' => 'pan',
        'billed_to_company' => true,
        'vat_amount' => 0,
        'category' => 'materials',
        'status' => 'pending',
    ];

    protected static function booted(): void
    {
        static::saving(function (PurchaseBill $bill): void {
            // A subcontractor's bill against a work order belongs to that order's project and vendor.
            if ($bill->work_order_id && ($order = WorkOrder::find($bill->work_order_id))) {
                $bill->project_id = $order->project_id;
                $bill->vendor_id = $order->vendor_id;
                $bill->category = 'subcontract';
                $bill->boq_item_id ??= $order->boq_item_id;
            }

            if ($bill->bill_type !== 'vat') {
                $bill->vat_amount = 0;
            }

            $bill->total_amount = round((float) $bill->base_amount + (float) $bill->vat_amount, 2);
            $bill->vendor_pan_vat ??= $bill->vendor?->pan_vat_no;
            // Brief §2 rule 1: computed from settings and the bill date, never typed.
            $bill->vat_claimable = CompanySetting::current()->vatClaimable($bill->bill_type, (bool) $bill->billed_to_company, $bill->bill_date);
        });

        static::saved(fn (PurchaseBill $bill) => ProjectCost::syncPurchaseBill($bill));
        static::deleted(fn (PurchaseBill $bill) => ProjectCost::syncPurchaseBill($bill, removed: true));
        static::created(fn (PurchaseBill $bill) => $bill->status === 'pending' ? Alerts::purchaseBillSubmitted($bill) : null);
    }

    protected function casts(): array
    {
        return [
            'bill_date' => 'date',
            'base_amount' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'billed_to_company' => 'boolean',
            'vat_claimable' => 'boolean',
            'items' => 'array',
            'photos' => 'array',
            'approved_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function scopeApproved(Builder $query): void
    {
        $query->where('status', 'approved');
    }

    /**
     * Rule 5: a bill made out to someone other than the company needs a super admin's review first.
     */
    public function isFlagged(): bool
    {
        return ! $this->billed_to_company;
    }

    /**
     * Rule 2: base amount when VAT is claimable, otherwise the full amount paid.
     */
    public function ledgerCost(): float
    {
        return (float) ($this->vat_claimable ? $this->base_amount : $this->total_amount);
    }

    /**
     * Supplier VAT that stays in cost (the report's "VAT paid to suppliers (not claimable)").
     */
    public function vatNotClaimable(): float
    {
        return $this->vat_claimable ? 0.0 : (float) $this->vat_amount;
    }

    public function amountPaid(): float
    {
        return round((float) $this->payments()->sum('amount'), 2);
    }

    public function amountOwed(): float
    {
        return round((float) $this->total_amount - $this->amountPaid(), 2);
    }

    public function paidStatus(): string
    {
        $paid = $this->amountPaid();

        return match (true) {
            $paid <= 0 => 'Unpaid',
            $paid + 0.005 < (float) $this->total_amount => 'Part paid',
            default => 'Paid',
        };
    }

    public static function duplicateOf(int|string|null $vendorId, ?string $billNo, ?int $ignoreId = null): ?self
    {
        if (blank($vendorId) || blank($billNo)) {
            return null;
        }

        return static::query()
            ->where('vendor_id', $vendorId)
            ->whereRaw('LOWER(TRIM(bill_no)) = ?', [strtolower(trim($billNo))])
            ->when($ignoreId, fn (Builder $query) => $query->whereKeyNot($ignoreId))
            ->first();
    }

    public static function canBeReviewedBy(?User $user, self $bill): bool
    {
        return $user !== null
            && $user->hasSitePower('approve_purchase_bills')
            && ((int) $bill->entered_by !== (int) $user->getKey() || $user->hasRole('super_admin'))
            && (! $bill->isFlagged() || $user->hasRole('super_admin'));
    }

    public function approve(User $by, ?string $note = null): void
    {
        $this->ensureReviewer($by);
        $this->forceFill(['status' => 'approved', 'approved_by' => $by->getKey(), 'approved_at' => now(), 'review_note' => $note])->save();

        Alerts::purchaseBillReviewed($this);
    }

    public function reject(User $by, string $note): void
    {
        $this->ensureReviewer($by);
        $this->forceFill(['status' => 'rejected', 'approved_by' => $by->getKey(), 'approved_at' => now(), 'review_note' => $note])->save();

        Alerts::purchaseBillReviewed($this);
    }

    /**
     * Back to pending for correction; its cost leaves the ledger until approved again.
     */
    public function reopen(): void
    {
        $this->forceFill(['status' => 'pending', 'approved_by' => null, 'approved_at' => null])->save();
    }

    protected function ensureReviewer(User $by): void
    {
        if (static::canBeReviewedBy($by, $this)) {
            return;
        }

        throw ValidationException::withMessages(['status' => match (true) {
            (int) $this->entered_by === (int) $by->getKey() => 'You entered this bill, so someone else must approve it.',
            $this->isFlagged() => 'This bill is not made out to the company. Only a super admin can accept it after review.',
            default => 'You are not allowed to approve purchase bills.',
        }]);
    }

    /**
     * @return list<string>
     */
    public function photoUrls(): array
    {
        return collect($this->photos ?? [])->map(fn (string $path): string => Storage::disk('public')->url($path))->values()->all();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class)->withTrashed();
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(VendorPayment::class);
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
