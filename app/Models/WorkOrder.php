<?php

namespace App\Models;

use App\Notifications\Alerts;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * An agreed job for a subcontractor (e.g. plumbing for Rs 1,50,000). Once approved, what is not yet billed
 * is "committed" cost; their bills against it become actual cost.
 */
#[Fillable([
    'project_id', 'vendor_id', 'number', 'scope', 'terms', 'agreed_amount', 'start_date', 'end_date',
    'status', 'entered_by', 'approved_by', 'approved_at', 'review_note', 'boq_item_id',
])]
class WorkOrder extends Model
{
    use LogsActivity;

    /**
     * @var array<string, string>
     */
    public const STATUSES = [
        'pending' => 'Pending approval',
        'approved' => 'Approved (open)',
        'rejected' => 'Rejected',
        'closed' => 'Closed',
        'cancelled' => 'Cancelled',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['status' => 'pending'];

    protected static function booted(): void
    {
        static::creating(function (WorkOrder $order): void {
            $order->number ??= 'WO-'.now()->format('Y').'-'.str_pad((string) (static::query()->whereYear('created_at', now()->year)->count() + 1), 3, '0', STR_PAD_LEFT);
        });

        static::created(fn (WorkOrder $order) => Alerts::workOrderSubmitted($order));
    }

    protected function casts(): array
    {
        return [
            'agreed_amount' => 'decimal:2',
            'start_date' => 'date',
            'end_date' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnlyDirty();
    }

    public function scopeOpen(Builder $query): void
    {
        $query->where('status', 'approved');
    }

    public function label(): string
    {
        return "{$this->number} · {$this->vendor?->name} · {$this->scope}";
    }

    /**
     * Approved bills against this order (bill totals, what we owe the subcontractor).
     */
    public function billedAmount(?PurchaseBill $ignore = null): float
    {
        return round((float) $this->bills()->approved()->when($ignore, fn (Builder $query) => $query->whereKeyNot($ignore->getKey()))->sum('total_amount'), 2);
    }

    public function remaining(?PurchaseBill $ignore = null): float
    {
        return round((float) $this->agreed_amount - $this->billedAmount($ignore), 2);
    }

    /**
     * Committed, not yet billed: only while the order is open.
     */
    public function committed(): float
    {
        return $this->status === 'approved' ? max(0.0, $this->remaining()) : 0.0;
    }

    public static function canBeReviewedBy(?User $user, self $order): bool
    {
        return $user !== null
            && $user->hasSitePower('approve_work_orders')
            && (int) $order->entered_by !== (int) $user->getKey();
    }

    public function approve(User $by, ?string $note = null): void
    {
        $this->ensureReviewer($by);
        $this->forceFill(['status' => 'approved', 'approved_by' => $by->getKey(), 'approved_at' => now(), 'review_note' => $note])->save();

        Alerts::workOrderReviewed($this);
    }

    public function reject(User $by, string $note): void
    {
        $this->ensureReviewer($by);
        $this->forceFill(['status' => 'rejected', 'approved_by' => $by->getKey(), 'approved_at' => now(), 'review_note' => $note])->save();

        Alerts::workOrderReviewed($this);
    }

    /**
     * Work finished (or stopped): whatever was not billed is no longer committed.
     */
    public function close(string $status = 'closed', ?string $note = null): void
    {
        $this->forceFill(['status' => $status, 'review_note' => $note ?? $this->review_note])->save();
    }

    protected function ensureReviewer(User $by): void
    {
        if (! static::canBeReviewedBy($by, $this)) {
            throw ValidationException::withMessages(['status' => (int) $this->entered_by === (int) $by->getKey()
                ? 'You entered this work order, so someone else must approve it.'
                : 'You are not allowed to approve work orders.']);
        }
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class)->withTrashed();
    }

    public function bills(): HasMany
    {
        return $this->hasMany(PurchaseBill::class);
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
