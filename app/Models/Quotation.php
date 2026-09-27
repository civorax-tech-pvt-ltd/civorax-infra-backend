<?php

namespace App\Models;

use App\Filament\Resources\ProjectResource;
use App\Notifications\Alerts;
use App\Notifications\NotifyAdmins;
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
        'changes_requested' => 'Changes requested',
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

            if ($quotation->status === 'sent' && ($quotation->wasRecentlyCreated || $quotation->wasChanged('status'))) {
                Alerts::quotationSent($quotation);
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
            'client_responded_at' => 'datetime',
        ];
    }

    /**
     * The client may accept or ask for changes only while the quotation is sent and still valid.
     */
    public function isOpenForClient(): bool
    {
        return $this->status === 'sent'
            && ($this->valid_until === null || $this->valid_until->endOfDay()->isFuture());
    }

    /**
     * The client asks for a revision; staff then edit this quotation or send a new version.
     */
    public function requestChanges(User $client, string $note): void
    {
        $this->forceFill([
            'status' => 'changes_requested',
            'client_note' => $note,
            'client_responded_at' => now(),
        ])->save();

        NotifyAdmins::send(
            title: "Changes requested on {$this->label()}",
            body: "{$client->name} — {$this->project->title}: \"".str($note)->limit(150).'"',
            url: $this->projectQuotationsUrl(),
            icon: 'heroicon-o-chat-bubble-left-ellipsis',
            color: 'warning',
        );
    }

    /**
     * The admin project page, opened on its Quotations tab.
     */
    public function projectQuotationsUrl(): string
    {
        return ProjectResource::getUrl('edit', ['record' => $this->project_id, 'activeRelationManager' => 2], panel: 'admin');
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
    public function accept(?User $acceptedBy = null): void
    {
        DB::transaction(function () use ($acceptedBy): void {
            static::query()
                ->where('project_id', $this->project_id)
                ->whereKeyNot($this->getKey())
                ->where('status', 'accepted')
                ->update(['status' => 'superseded']);

            $this->update(['status' => 'accepted']);
            $this->forceFill([
                'accepted_at' => now(),
                'accepted_by' => $acceptedBy?->getKey() ?? auth()->id(),
            ])->saveQuietly();

            $this->project->update(['fee' => $this->total]);
        });

        if ($acceptedBy?->client !== null) {
            NotifyAdmins::send(
                title: "{$this->label()} accepted by client",
                body: "{$acceptedBy->name} accepted NPR ".number_format((float) $this->total, 2)." for {$this->project->title}. The contract fee is now set.",
                url: $this->projectQuotationsUrl(),
                icon: 'heroicon-o-check-badge',
                color: 'success',
            );
        }
    }

    /**
     * Why acceptance cannot be undone, or null when it can: the fee would drop below what is already paid.
     */
    public function undoAcceptanceError(): ?string
    {
        $paid = $this->project->amountPaid();
        $previous = $this->previousAccepted();
        $feeAfter = $previous?->total;

        if ($paid > 0 && ($feeAfter === null || $paid > (float) $feeAfter)) {
            return 'NPR '.number_format($paid, 2).' has already been paid against this price. '
                .'Create and accept a new quotation instead.';
        }

        return null;
    }

    /**
     * Return an accepted quotation to "sent" so it can be edited or deleted. The fee falls back
     * to the previously accepted quotation, or is cleared when there was none.
     */
    public function undoAcceptance(): void
    {
        DB::transaction(function (): void {
            $previous = $this->previousAccepted();

            $this->forceFill(['status' => 'sent', 'accepted_at' => null, 'accepted_by' => null])->save();
            $previous?->forceFill(['status' => 'accepted'])->save();

            $this->project->update(['fee' => $previous?->total]);
        });
    }

    protected function previousAccepted(): ?Quotation
    {
        return static::query()
            ->where('project_id', $this->project_id)
            ->whereKeyNot($this->getKey())
            ->where('status', 'superseded')
            ->latest('accepted_at')
            ->first();
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

    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
