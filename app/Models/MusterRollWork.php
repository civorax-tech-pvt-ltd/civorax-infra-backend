<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Part III of a muster roll: work performed, with its Measurement Book page reference.
 */
#[Fillable(['muster_roll_id', 'labourer_id', 'description', 'quantity', 'unit', 'mb_ref', 'remarks', 'sort'])]
class MusterRollWork extends Model
{
    protected function casts(): array
    {
        return ['quantity' => 'decimal:2'];
    }

    public function musterRoll(): BelongsTo
    {
        return $this->belongsTo(MusterRoll::class);
    }

    public function labourer(): BelongsTo
    {
        return $this->belongsTo(Labourer::class)->withTrashed();
    }
}
