<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * One line of a project's BOQ, copied from the master library (its rate is the project's own).
 * Progress comes only from approved, cumulative measurements of quantity, never from cost.
 */
#[Fillable([
    'project_id', 'master_item_id', 'code', 'description', 'unit', 'quantity', 'rate',
    'planned_start', 'planned_end', 'is_variation', 'norms', 'sort', 'created_by',
])]
class BoqItem extends Model
{
    use LogsActivity;

    /**
     * @var array<string, string>
     */
    public const STATUSES = [
        'not_started' => 'Not started',
        'in_progress' => 'In progress',
        'completed' => 'Completed',
    ];

    protected static function booted(): void
    {
        static::saving(function (BoqItem $item): void {
            $item->planned_value = round((float) $item->quantity * (float) $item->rate, 2);
        });
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'rate' => 'decimal:2',
            'planned_value' => 'decimal:2',
            'planned_start' => 'date',
            'planned_end' => 'date',
            'is_variation' => 'boolean',
            'norms' => 'array',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnlyDirty();
    }

    /**
     * Latest approved cumulative quantity executed to date.
     */
    public function executedQuantity(): float
    {
        return (float) ($this->latestApprovedMeasurement?->executed_quantity ?? 0);
    }

    /**
     * Executed ÷ BOQ quantity, not capped: above 100 means more work than the BOQ allows.
     */
    public function progressPercent(): float
    {
        return (float) $this->quantity > 0 ? round($this->executedQuantity() / (float) $this->quantity * 100, 1) : 0.0;
    }

    public function isExcess(): bool
    {
        return $this->executedQuantity() > (float) $this->quantity + 0.0005;
    }

    /**
     * Value of work done for project progress (executed × rate). Excess quantity is left out here, because
     * it is not part of the BOQ until approved as a variation; the item itself still shows the excess.
     */
    public function earnedValue(): float
    {
        return round(min($this->executedQuantity(), (float) $this->quantity) * (float) $this->rate, 2);
    }

    public function status(): string
    {
        $executed = $this->executedQuantity();

        return match (true) {
            $executed <= 0 => 'not_started',
            $executed >= (float) $this->quantity => 'completed',
            default => 'in_progress',
        };
    }

    /**
     * Where the work should be today from the planned dates (straight line), or null without dates.
     */
    public function plannedPercentToday(?Carbon $today = null): ?float
    {
        if ($this->planned_start === null || $this->planned_end === null) {
            return null;
        }

        // Whole calendar days: today's Nepal date against the planned dates, with no time-zone drift.
        $today = Carbon::parse(($today ?? now(config('app.business_timezone')))->toDateString());
        $start = Carbon::parse($this->planned_start->toDateString());
        $end = Carbon::parse($this->planned_end->toDateString());

        if ($today->lt($start)) {
            return 0.0;
        }

        if ($today->gte($end)) {
            return 100.0;
        }

        return round((int) $start->diffInDays($today) / max(1, (int) $start->diffInDays($end)) * 100, 1);
    }

    /**
     * On track or Delayed against the planned dates; null when the item has no dates.
     */
    public function scheduleStatus(?Carbon $today = null): ?string
    {
        $planned = $this->plannedPercentToday($today);

        if ($planned === null) {
            return null;
        }

        return $this->status() !== 'completed' && $this->progressPercent() + 0.5 < $planned ? 'delayed' : 'on_track';
    }

    public function scheduleVariance(?Carbon $today = null): ?float
    {
        $planned = $this->plannedPercentToday($today);

        return $planned === null ? null : round(min(100, $this->progressPercent()) - $planned, 1);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function masterItem(): BelongsTo
    {
        return $this->belongsTo(BoqMasterItem::class, 'master_item_id');
    }

    public function measurements(): HasMany
    {
        return $this->hasMany(BoqMeasurement::class)->latest('measured_date')->latest('id');
    }

    /**
     * Ledger entries tagged to this item (direct costs).
     */
    public function costs(): HasMany
    {
        return $this->hasMany(ProjectCost::class);
    }

    public function materialIssues(): HasMany
    {
        return $this->hasMany(MaterialIssue::class);
    }

    public function label(): string
    {
        return trim(($this->code ? "{$this->code} · " : '').$this->description)." ({$this->unit})";
    }

    public function latestApprovedMeasurement(): HasOne
    {
        return $this->hasOne(BoqMeasurement::class)->ofMany(
            ['measured_date' => 'max', 'id' => 'max'],
            fn ($query) => $query->where('status', 'approved'),
        );
    }
}
