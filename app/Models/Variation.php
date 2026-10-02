<?php

namespace App\Models;

use App\Notifications\Alerts;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Client-approved extra work: once approved, its amount adds to the contract value (and what the client owes),
 * and its expected extra cost adds to that category's budget.
 */
#[Fillable([
    'project_id', 'title', 'description', 'amount', 'cost_budget', 'budget_category', 'client_reference',
    'client_approved_on', 'document_path', 'status', 'entered_by', 'approved_by', 'approved_at', 'review_note',
])]
class Variation extends Model
{
    use LogsActivity;

    /**
     * @var array<string, string>
     */
    public const STATUSES = PurchaseBill::STATUSES;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['status' => 'pending', 'cost_budget' => 0];

    protected static function booted(): void
    {
        static::created(fn (Variation $variation) => Alerts::variationSubmitted($variation));
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'cost_budget' => 'decimal:2',
            'client_approved_on' => 'date',
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

    public static function canBeReviewedBy(?User $user, self $variation): bool
    {
        return $user !== null
            && $user->hasSitePower('approve_variations')
            && ((int) $variation->entered_by !== (int) $user->getKey() || $user->hasRole('super_admin'));
    }

    public function approve(User $by, ?string $note = null): void
    {
        $this->ensureReviewer($by);
        $this->forceFill(['status' => 'approved', 'approved_by' => $by->getKey(), 'approved_at' => now(), 'review_note' => $note])->save();

        Alerts::variationApproved($this);
    }

    public function reject(User $by, string $note): void
    {
        $this->ensureReviewer($by);
        $this->forceFill(['status' => 'rejected', 'approved_by' => $by->getKey(), 'approved_at' => now(), 'review_note' => $note])->save();

        Alerts::variationRejected($this);
    }

    protected function ensureReviewer(User $by): void
    {
        if (! static::canBeReviewedBy($by, $this)) {
            throw ValidationException::withMessages(['status' => (int) $this->entered_by === (int) $by->getKey()
                ? 'You entered this variation, so someone else must approve it.'
                : 'You are not allowed to approve variations.']);
        }
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }
}
