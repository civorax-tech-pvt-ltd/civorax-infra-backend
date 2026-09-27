<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable(['project_id', 'version', 'status', 'discount', 'vat_percent', 'valid_until', 'notes', 'created_by'])]
class Quotation extends Model
{
    use LogsActivity;

    /**
     * @var array<string, string>
     */
    public const STATUSES = [
        'draft' => 'Draft',
        'sent' => 'Sent',
        'accepted' => 'Accepted',
        'rejected' => 'Rejected',
        'superseded' => 'Superseded',
    ];

    /**
     * Units offered for line items; free text is also allowed.
     *
     * @var array<string, string>
     */
    public const UNITS = [
        'lump sum' => 'Lump sum',
        'sq.ft' => 'sq.ft',
        'sq.m' => 'sq.m',
        'rft' => 'Running ft',
        'nos' => 'Nos',
        'visit' => 'Visit',
        '%' => '% of cost',
    ];

    protected static function booted(): void
    {
        static::creating(function (Quotation $quotation): void {
            $quotation->version = (int) static::query()->where('project_id', $quotation->project_id)->max('version') + 1;
        });

        static::saved(function (Quotation $quotation): void {
            if ($quotation->wasChanged(['discount', 'vat_percent'])) {
                $quotation->recalculateTotals();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'vat_percent' => 'decimal:2',
            'total' => 'decimal:2',
            'valid_until' => 'date',
            'accepted_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnlyDirty();
    }

    public function recalculateTotals(): void
    {
        $subtotal = (float) $this->items()->sum('amount');
        $taxable = max(0, $subtotal - (float) $this->discount);

        $this->forceFill([
            'subtotal' => $subtotal,
            'total' => round($taxable * (1 + (float) $this->vat_percent / 100), 2),
        ])->saveQuietly();
    }

    /**
     * Accept this quotation: it becomes the project's contract fee and replaces any earlier accepted one.
     */
    public function accept(): void
    {
        DB::transaction(function (): void {
            static::query()
                ->where('project_id', $this->project_id)
                ->whereKeyNot($this->getKey())
                ->where('status', 'accepted')
                ->update(['status' => 'superseded']);

            $this->update(['status' => 'accepted']);
            $this->forceFill(['accepted_at' => now()])->saveQuietly();

            $this->project->update(['fee' => $this->total]);
        });
    }

    public function label(): string
    {
        return "Quotation v{$this->version}";
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class)->orderBy('sort');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
