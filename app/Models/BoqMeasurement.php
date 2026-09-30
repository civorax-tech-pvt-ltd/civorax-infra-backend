<?php

namespace App\Models;

use App\Notifications\Alerts;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A measurement of work done on a BOQ item: the cumulative quantity executed up to a date, with site photos.
 * Only approved measurements count; every one is kept as history.
 */
#[Fillable([
    'boq_item_id', 'measured_date', 'executed_quantity', 'photos', 'remarks',
    'status', 'entered_by', 'approved_by', 'approved_at', 'review_note',
])]
class BoqMeasurement extends Model
{
    use LogsActivity;

    /**
     * @var array<string, string>
     */
    public const STATUSES = [
        'pending' => 'Pending',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
    ];

    protected static function booted(): void
    {
        static::created(fn (BoqMeasurement $measurement) => Alerts::measurementSubmitted($measurement));
    }

    protected function casts(): array
    {
        return [
            'measured_date' => 'date',
            'executed_quantity' => 'decimal:3',
            'photos' => 'array',
            'approved_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnlyDirty();
    }

    /**
     * Approvers are chosen per entry type (Approval Settings); nobody approves their own entry.
     */
    public static function canBeReviewedBy(?User $user, self $measurement): bool
    {
        return $user !== null
            && $user->hasSitePower('approve_boq_measurements')
            && (int) $measurement->entered_by !== (int) $user->getKey();
    }

    public function approve(User $by, ?string $note = null): void
    {
        $this->ensureReviewer($by);
        $this->forceFill(['status' => 'approved', 'approved_by' => $by->getKey(), 'approved_at' => now(), 'review_note' => $note])->save();

        Alerts::measurementReviewed($this);
    }

    public function reject(User $by, string $note): void
    {
        $this->ensureReviewer($by);
        $this->forceFill(['status' => 'rejected', 'approved_by' => $by->getKey(), 'approved_at' => now(), 'review_note' => $note])->save();

        Alerts::measurementReviewed($this);
    }

    protected function ensureReviewer(User $by): void
    {
        if (! static::canBeReviewedBy($by, $this)) {
            throw ValidationException::withMessages([
                'status' => (int) $this->entered_by === (int) $by->getKey()
                    ? 'You entered this measurement, so someone else must approve it.'
                    : 'You are not allowed to approve BOQ measurements.',
            ]);
        }
    }

    /**
     * @return list<string>
     */
    public function photoUrls(): array
    {
        return collect($this->photos ?? [])->map(fn (string $path): string => Storage::disk('public')->url($path))->values()->all();
    }

    public function boqItem(): BelongsTo
    {
        return $this->belongsTo(BoqItem::class);
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
