<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A vendor's signed confirmation of the balance on a date (typically at fiscal year end).
 */
#[Fillable(['vendor_id', 'as_of', 'balance', 'agreed', 'note', 'document_path', 'recorded_by'])]
class VendorBalanceConfirmation extends Model
{
    protected function casts(): array
    {
        return [
            'as_of' => 'date',
            'balance' => 'decimal:2',
            'agreed' => 'boolean',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
