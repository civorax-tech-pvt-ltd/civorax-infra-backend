<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * The project cost ledger: every approved cost lands here under a category, whatever its source
 * (muster roll now; purchase bills, petty cash, work orders later). Reports read only this table.
 */
#[Fillable([
    'project_id', 'category', 'source_type', 'source_id', 'description', 'amount', 'vat_not_claimable',
    'entry_date', 'status', 'approved_by', 'approved_at', 'boq_item_id',
])]
class ProjectCost extends Model
{
    use LogsActivity;

    /**
     * @var array<string, string>
     */
    public const CATEGORIES = [
        'materials' => 'Materials',
        'labour' => 'Labour',
        'subcontract' => 'Subcontract',
        'equipment' => 'Equipment / machinery hire',
        'transport' => 'Transport',
        'site_expenses' => 'Site expenses',
        'contingency' => 'Contingency',
    ];

    /**
     * @var array<string, string>
     */
    public const SOURCES = [
        'muster_roll' => 'Muster roll',
        'purchase_bill' => 'Purchase bill',
        'petty_cash' => 'Petty cash',
        'work_order' => 'Subcontract bill',
        'equipment' => 'Equipment / transport',
        'material_transfer_out' => 'Material transfer (out)',
        'material_transfer_in' => 'Material transfer (in)',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'vat_not_claimable' => 'decimal:2',
            'entry_date' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnlyDirty();
    }

    public function scopeApproved(Builder $query): void
    {
        $query->where('status', 'approved');
    }

    /**
     * Keep a muster roll's labour cost in the ledger while it is approved, and remove it otherwise.
     */
    public static function syncMusterRoll(MusterRoll $roll): void
    {
        if (! $roll->exists || $roll->status !== 'approved') {
            static::query()->where('source_type', 'muster_roll')->where('source_id', $roll->getKey())->delete();

            return;
        }

        static::query()->updateOrCreate(
            ['source_type' => 'muster_roll', 'source_id' => $roll->getKey()],
            [
                'project_id' => $roll->project_id,
                'category' => 'labour',
                'boq_item_id' => $roll->boq_item_id,
                'description' => "Labour wages {$roll->label()}",
                'amount' => $roll->totalWage(),
                'entry_date' => $roll->ends_on,
                'status' => 'approved',
                'approved_by' => $roll->approved_by,
                'approved_at' => $roll->approved_at ?? now(),
            ],
        );
    }

    /**
     * An approved bill is one ledger entry at its rule-2 cost; any other status (or deletion) removes it.
     */
    public static function syncPurchaseBill(PurchaseBill $bill, bool $removed = false): void
    {
        static::syncSource('purchase_bill', $bill->getKey(), ! $removed && $bill->status === 'approved', fn (): array => [
            'project_id' => $bill->project_id,
            'category' => $bill->category ?: 'materials',
            'boq_item_id' => $bill->boq_item_id,
            'description' => trim(($bill->vendor?->name ?? 'Vendor')." bill {$bill->bill_no}".($bill->description ? " · {$bill->description}" : '')),
            'amount' => $bill->ledgerCost(),
            'vat_not_claimable' => $bill->vatNotClaimable(),
            'entry_date' => $bill->bill_date,
            'approved_by' => $bill->approved_by,
            'approved_at' => $bill->approved_at ?? now(),
        ]);
    }

    public static function syncPettyCash(PettyCashClaim $claim, bool $removed = false): void
    {
        static::syncSource('petty_cash', $claim->getKey(), ! $removed && $claim->status === 'approved', fn (): array => [
            'project_id' => $claim->project_id,
            'category' => $claim->category ?: 'site_expenses',
            'boq_item_id' => $claim->boq_item_id,
            'description' => $claim->description,
            'amount' => (float) $claim->amount,
            'vat_not_claimable' => 0,
            'entry_date' => $claim->expense_date,
            'approved_by' => $claim->approved_by,
            'approved_at' => $claim->approved_at ?? now(),
        ]);
    }

    public static function syncEquipment(EquipmentEntry $entry, bool $removed = false): void
    {
        static::syncSource('equipment', $entry->getKey(), ! $removed && $entry->status === 'approved', fn (): array => [
            'project_id' => $entry->project_id,
            'category' => $entry->kind === 'transport' ? 'transport' : 'equipment',
            'boq_item_id' => $entry->boq_item_id,
            'description' => trim($entry->description.' · '.rtrim(rtrim(number_format((float) $entry->quantity, 2), '0'), '.').' '.(EquipmentEntry::UNITS[$entry->unit] ?? $entry->unit).($entry->vendor ? " ({$entry->vendor->name})" : '')),
            'amount' => (float) $entry->amount,
            'vat_not_claimable' => 0,
            'entry_date' => $entry->entry_date,
            'approved_by' => $entry->approved_by,
            'approved_at' => $entry->approved_at ?? now(),
        ]);
    }

    /**
     * A transfer takes its value out of the sending project (negative entry) and, unless the material went
     * back to the supplier, puts it into the receiving project.
     */
    public static function syncMaterialTransfer(MaterialTransfer $transfer, bool $removed = false): void
    {
        $label = "{$transfer->material?->name} ".rtrim(rtrim(number_format((float) $transfer->quantity, 2), '0'), '.')." {$transfer->material?->unit}";

        static::syncSource('material_transfer_out', $transfer->getKey(), ! $removed, fn (): array => [
            'project_id' => $transfer->from_project_id,
            'category' => 'materials',
            'description' => $transfer->isReturn() ? "Returned to supplier: {$label}" : "Transferred to {$transfer->toProject?->title}: {$label}",
            'amount' => -abs((float) $transfer->value),
            'vat_not_claimable' => 0,
            'entry_date' => $transfer->transferred_on,
            'approved_by' => $transfer->entered_by,
            'approved_at' => now(),
        ]);

        static::syncSource('material_transfer_in', $transfer->getKey(), ! $removed && ! $transfer->isReturn(), fn (): array => [
            'project_id' => $transfer->to_project_id,
            'category' => 'materials',
            'description' => "Transferred from {$transfer->fromProject?->title}: {$label}",
            'amount' => abs((float) $transfer->value),
            'vat_not_claimable' => 0,
            'entry_date' => $transfer->transferred_on,
            'approved_by' => $transfer->entered_by,
            'approved_at' => now(),
        ]);
    }

    /**
     * @param  \Closure(): array<string, mixed>  $attributes
     */
    protected static function syncSource(string $type, int|string $id, bool $approved, \Closure $attributes): void
    {
        if (! $approved) {
            static::query()->where('source_type', $type)->where('source_id', $id)->delete();

            return;
        }

        static::query()->updateOrCreate(['source_type' => $type, 'source_id' => $id], [...$attributes(), 'status' => 'approved']);
    }

    public function sourceLabel(): string
    {
        return self::SOURCES[$this->source_type] ?? $this->source_type;
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function boqItem(): BelongsTo
    {
        return $this->belongsTo(BoqItem::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
