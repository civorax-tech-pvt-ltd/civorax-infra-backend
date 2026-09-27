<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['quotation_id', 'description', 'quantity', 'unit', 'rate', 'sort'])]
class QuotationItem extends Model
{
    protected static function booted(): void
    {
        static::saving(function (QuotationItem $item): void {
            $item->amount = $item->unit === '%'
                ? round((float) $item->quantity * (float) $item->rate / 100, 2)
                : round((float) $item->quantity * (float) $item->rate, 2);
        });

        static::saved(fn (QuotationItem $item) => $item->quotation?->recalculateTotals());
        static::deleted(fn (QuotationItem $item) => $item->quotation?->recalculateTotals());
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'rate' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }
}
