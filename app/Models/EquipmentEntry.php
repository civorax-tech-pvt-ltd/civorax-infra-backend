<?php

namespace App\Models;

use App\Notifications\Alerts;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Machine hire or a transport trip (JCB 6 hours, tipper 4 trips). Quantity × rate is the cost; when a provider
 * is named, the entry is what we owe them (it counts as billed in the vendor ledger, so don't also enter a bill).
 */
#[Fillable([
    'project_id', 'vendor_id', 'kind', 'description', 'entry_date', 'unit', 'quantity', 'rate', 'photos', 'note',
    'status', 'entered_by', 'approved_by', 'approved_at', 'review_note', 'boq_item_id',
])]
class EquipmentEntry extends Model
{
    use LogsActivity;

    /**
     * @var array<string, string>
     */
    public const KINDS = [
        'equipment' => 'Equipment / machinery',
        'transport' => 'Transport',
    ];

    /**
     * @var array<string, string>
     */
    public const UNITS = [
        'hour' => 'Hours',
        'day' => 'Days',
        'trip' => 'Trips',
        'km' => 'Km',
        'lump_sum' => 'Lump sum',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['kind' => 'equipment', 'unit' => 'hour', 'status' => 'pending'];

    protected static function booted(): void
    {
        static::saving(fn (EquipmentEntry $entry) => $entry->amount = round((float) $entry->quantity * (float) $entry->rate, 2));
        static::saved(fn (EquipmentEntry $entry) => ProjectCost::syncEquipment($entry));
        static::deleted(fn (EquipmentEntry $entry) => ProjectCost::syncEquipment($entry, removed: true));
        static::created(fn (EquipmentEntry $entry) => Alerts::equipmentSubmitted($entry));
    }

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'quantity' => 'decimal:2',
            'rate' => 'decimal:2',
            'amount' => 'decimal:2',
            'photos' => 'array',
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

    public static function canBeReviewedBy(?User $user, self $entry): bool
    {
        return $user !== null
            && $user->hasSitePower('approve_equipment')
            && (int) $entry->entered_by !== (int) $user->getKey();
    }

    public function approve(User $by, ?string $note = null): void
    {
        $this->ensureReviewer($by);
        $this->forceFill(['status' => 'approved', 'approved_by' => $by->getKey(), 'approved_at' => now(), 'review_note' => $note])->save();
    }

    public function reject(User $by, string $note): void
    {
        $this->ensureReviewer($by);
        $this->forceFill(['status' => 'rejected', 'approved_by' => $by->getKey(), 'approved_at' => now(), 'review_note' => $note])->save();
    }

    protected function ensureReviewer(User $by): void
    {
        if (! static::canBeReviewedBy($by, $this)) {
            throw ValidationException::withMessages(['status' => (int) $this->entered_by === (int) $by->getKey()
                ? 'You entered this, so someone else must approve it.'
                : 'You are not allowed to approve equipment and transport entries.']);
        }
    }

    /**
     * @return list<string>
     */
    public function photoUrls(): array
    {
        return collect($this->photos ?? [])->map(fn (string $path): string => Storage::disk('public')->url($path))->values()->all();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class)->withTrashed();
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }
}
