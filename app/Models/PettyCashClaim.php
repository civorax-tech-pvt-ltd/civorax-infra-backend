<?php

namespace App\Models;

use App\Notifications\Alerts;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A small site expense paid by a supervisor and claimed back with a receipt photo. No VAT (brief rule 7).
 */
#[Fillable([
    'project_id', 'claimed_by', 'expense_date', 'category', 'description', 'amount', 'receipts',
    'status', 'approved_by', 'approved_at', 'review_note', 'reimbursed_on', 'reimbursed_by', 'boq_item_id',
])]
class PettyCashClaim extends Model
{
    use LogsActivity;

    /**
     * @var array<string, string>
     */
    public const STATUSES = PurchaseBill::STATUSES;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'category' => 'site_expenses',
        'status' => 'pending',
    ];

    protected static function booted(): void
    {
        static::saved(fn (PettyCashClaim $claim) => ProjectCost::syncPettyCash($claim));
        static::deleted(fn (PettyCashClaim $claim) => ProjectCost::syncPettyCash($claim, removed: true));
        static::created(fn (PettyCashClaim $claim) => Alerts::pettyCashSubmitted($claim));
    }

    protected function casts(): array
    {
        return [
            'expense_date' => 'date',
            'amount' => 'decimal:2',
            'receipts' => 'array',
            'approved_at' => 'datetime',
            'reimbursed_on' => 'date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnlyDirty();
    }

    public static function canBeReviewedBy(?User $user, self $claim): bool
    {
        return $user !== null
            && $user->hasSitePower('approve_petty_cash')
            && (int) $claim->claimed_by !== (int) $user->getKey();
    }

    public function approve(User $by, ?string $note = null): void
    {
        $this->ensureReviewer($by);
        $this->forceFill(['status' => 'approved', 'approved_by' => $by->getKey(), 'approved_at' => now(), 'review_note' => $note])->save();

        Alerts::pettyCashReviewed($this);
    }

    public function reject(User $by, string $note): void
    {
        $this->ensureReviewer($by);
        $this->forceFill(['status' => 'rejected', 'approved_by' => $by->getKey(), 'approved_at' => now(), 'review_note' => $note])->save();

        Alerts::pettyCashReviewed($this);
    }

    /**
     * The claimant got their money back; counts as cash paid out for the project.
     */
    public function markReimbursed(User $by, CarbonInterface|string $on): void
    {
        if ($this->status !== 'approved') {
            throw ValidationException::withMessages(['status' => 'Only approved claims can be reimbursed.']);
        }

        $this->forceFill(['reimbursed_on' => $on, 'reimbursed_by' => $by->getKey()])->save();
    }

    protected function ensureReviewer(User $by): void
    {
        if (! static::canBeReviewedBy($by, $this)) {
            throw ValidationException::withMessages(['status' => (int) $this->claimed_by === (int) $by->getKey()
                ? 'You made this claim, so someone else must approve it.'
                : 'You are not allowed to approve petty-cash claims.']);
        }
    }

    /**
     * @return list<string>
     */
    public function receiptUrls(): array
    {
        return collect($this->receipts ?? [])->map(fn (string $path): string => Storage::disk('public')->url($path))->values()->all();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function claimant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
