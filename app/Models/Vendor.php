<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable(['name', 'vendor_type', 'contact', 'pan_vat_no', 'address', 'created_by'])]
class Vendor extends Model
{
    use LogsActivity, SoftDeletes;

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_vendors')
            ->withPivot('scope');
    }

    public function purchaseBills(): HasMany
    {
        return $this->hasMany(PurchaseBill::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(VendorPayment::class);
    }

    public function balanceConfirmations(): HasMany
    {
        return $this->hasMany(VendorBalanceConfirmation::class)->latest('as_of');
    }

    /**
     * Brief §2 rule 3: what we owe is always the bills' total amount, whatever the VAT treatment.
     */
    public function billedTotal(): float
    {
        return round((float) $this->purchaseBills()->approved()->sum('total_amount') + (float) $this->equipmentEntries()->approved()->sum('amount'), 2);
    }

    /**
     * Approved hire / trip entries naming this vendor are what we owe them (no separate bill is entered).
     */
    public function equipmentEntries(): HasMany
    {
        return $this->hasMany(EquipmentEntry::class);
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    public function paidTotal(): float
    {
        return round((float) $this->payments()->sum('amount'), 2);
    }

    public function outstanding(): float
    {
        return round($this->billedTotal() - $this->paidTotal(), 2);
    }

    /**
     * Statement lines (approved bills = credit to the vendor, payments = debit), oldest first, with running balance owed.
     *
     * @return array{opening: float, rows: list<array<string, mixed>>, billed: float, paid: float, closing: float}
     */
    public function statement(?string $from = null, ?string $until = null): array
    {
        $bills = $this->purchaseBills()->approved()->with('project')->get()->map(fn (PurchaseBill $bill): array => [
            'date' => $bill->bill_date,
            'order' => 0,
            'entry' => PurchaseBill::TYPES[$bill->bill_type]." {$bill->bill_no}",
            'site' => $bill->project?->title,
            'billed' => (float) $bill->total_amount,
            'paid' => 0.0,
        ]);

        $payments = $this->payments()->with(['project', 'bill'])->get()->map(fn (VendorPayment $payment): array => [
            'date' => $payment->paid_on,
            'order' => 1,
            'entry' => 'Payment'.($payment->bill ? " for bill {$payment->bill->bill_no}" : '').($payment->reference ? " · ref {$payment->reference}" : ''),
            'site' => $payment->project?->title,
            'billed' => 0.0,
            'paid' => (float) $payment->amount,
        ]);

        $hires = $this->equipmentEntries()->approved()->with('project')->get()->map(fn (EquipmentEntry $entry): array => [
            'date' => $entry->entry_date,
            'order' => 0,
            'entry' => (EquipmentEntry::KINDS[$entry->kind] ?? $entry->kind).": {$entry->description}",
            'site' => $entry->project?->title,
            'billed' => (float) $entry->amount,
            'paid' => 0.0,
        ]);

        $entries = $bills->concat($hires)->concat($payments)->sortBy(fn (array $entry): string => $entry['date']->toDateString().$entry['order'])->values();
        $inRange = fn (Carbon $date): bool => (! $from || $date->toDateString() >= $from) && (! $until || $date->toDateString() <= $until);

        $opening = $from ? round($entries->filter(fn (array $entry): bool => $entry['date']->toDateString() < $from)->sum(fn (array $entry): float => $entry['billed'] - $entry['paid']), 2) : 0.0;
        $balance = $opening;

        $rows = $entries->filter(fn (array $entry): bool => $inRange($entry['date']))->map(function (array $entry) use (&$balance): array {
            $balance = round($balance + $entry['billed'] - $entry['paid'], 2);

            return [...$entry, 'balance' => $balance];
        })->values()->all();

        $billed = round(array_sum(array_column($rows, 'billed')), 2);
        $paid = round(array_sum(array_column($rows, 'paid')), 2);

        return ['opening' => $opening, 'rows' => $rows, 'billed' => $billed, 'paid' => $paid, 'closing' => round($opening + $billed - $paid, 2)];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
