<?php

namespace App\Models;

use Anuzpandey\LaravelNepaliDate\LaravelNepaliDate;
use App\Notifications\Alerts;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A monthly muster roll for one site in the Nepal format:
 * Part I nominal roll (lines, built from daily labour attendance),
 * Part II arrears (unpaid wages) and Part III work performed.
 */
#[Fillable([
    'project_id', 'calendar', 'year', 'month', 'starts_on', 'ends_on', 'status',
    'prepared_by', 'submitted_at', 'approved_by', 'approved_at', 'review_note', 'boq_item_id',
])]
class MusterRoll extends Model
{
    use LogsActivity;

    /**
     * @var array<string, string>
     */
    public const STATUSES = [
        'draft' => 'Draft',
        'submitted' => 'Submitted',
        'approved' => 'Approved',
        'returned' => 'Returned',
    ];

    /**
     * @var array<string, string>
     */
    public const CALENDARS = [
        'bs' => 'Nepali (B.S.)',
        'ad' => 'English (A.D.)',
    ];

    /**
     * @var array<int, string>
     */
    public const BS_MONTHS = [
        1 => 'Baisakh', 2 => 'Jestha', 3 => 'Asar', 4 => 'Shrawan', 5 => 'Bhadra', 6 => 'Aswin',
        7 => 'Kartik', 8 => 'Mangsir', 9 => 'Poush', 10 => 'Magh', 11 => 'Falgun', 12 => 'Chaitra',
    ];

    protected static function booted(): void
    {
        static::saving(function (MusterRoll $roll): void {
            [$start, $end] = static::periodFor($roll->calendar, (int) $roll->year, (int) $roll->month);
            $roll->starts_on = $start;
            $roll->ends_on = $end;

            if ($error = static::overlapError($roll->project_id, $start, $end, $roll)) {
                throw ValidationException::withMessages(['month' => $error]);
            }
        });

        static::saved(function (MusterRoll $roll): void {
            if (! $roll->isLocked()) {
                $roll->rebuildLines();
            }

            // Approved wages are labour cost in the project cost ledger; returning the roll takes them out.
            ProjectCost::syncMusterRoll($roll);
        });

        static::deleted(fn (MusterRoll $roll) => ProjectCost::syncMusterRoll($roll));
    }

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'year' => 'integer',
            'month' => 'integer',
        ];
    }

    // ── Calendar ──────────────────────────────────────────────────────────────

    /**
     * First and last day (A.D.) of a B.S. or A.D. month.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function periodFor(string $calendar, int $year, int $month): array
    {
        if ($calendar === 'bs') {
            $start = Carbon::parse(LaravelNepaliDate::from(sprintf('%04d-%02d-01', $year, $month))->toEnglishDate());

            return [$start, $start->copy()->addDays(LaravelNepaliDate::daysInMonth($month, $year) - 1)];
        }

        $start = Carbon::create($year, $month, 1)->startOfDay();

        return [$start, $start->copy()->endOfMonth()->startOfDay()];
    }

    /**
     * The B.S. [year, month, day] of an A.D. date.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    public static function toBs(CarbonInterface|string $date): array
    {
        $bs = LaravelNepaliDate::from(Carbon::parse($date)->toDateString())->toNepaliDateArray();

        return [(int) $bs->year, (int) $bs->month, (int) $bs->day];
    }

    /**
     * "12 Aswin 2083" for an A.D. date.
     */
    public static function bsDate(CarbonInterface|string|null $date): ?string
    {
        if ($date === null) {
            return null;
        }

        [$year, $month, $day] = static::toBs($date);

        return "{$day} ".self::BS_MONTHS[$month]." {$year}";
    }

    /**
     * The month (in the given calendar) that contains a date, e.g. today's month for a new roll.
     *
     * @return array{0: int, 1: int}
     */
    public static function monthOf(string $calendar, CarbonInterface|string $date): array
    {
        if ($calendar === 'bs') {
            [$year, $month] = static::toBs($date);

            return [$year, $month];
        }

        $date = Carbon::parse($date);

        return [$date->year, $date->month];
    }

    /**
     * @return array<int, string>
     */
    public static function monthOptions(string $calendar): array
    {
        return $calendar === 'bs'
            ? self::BS_MONTHS
            : collect(range(1, 12))->mapWithKeys(fn (int $month): array => [$month => Carbon::create(2000, $month)->format('F')])->all();
    }

    public function label(): string
    {
        return ($this->calendar === 'bs' ? self::BS_MONTHS[$this->month] : Carbon::create($this->year, $this->month)->format('F'))." {$this->year}";
    }

    public function statusLabel(): string
    {
        return strtolower(self::STATUSES[$this->status] ?? $this->status);
    }

    /**
     * Every A.D. date of the period, keyed by day number (1 … 29-32) as printed on the roll.
     *
     * @return array<int, Carbon>
     */
    public function dayDates(): array
    {
        $dates = [];

        for ($day = 1, $date = $this->starts_on->copy(); $date->lte($this->ends_on); $day++, $date = $date->copy()->addDay()) {
            $dates[$day] = $date;
        }

        return $dates;
    }

    // ── Rules ─────────────────────────────────────────────────────────────────

    public static function overlapError(int|string|null $projectId, CarbonInterface $start, CarbonInterface $end, ?MusterRoll $ignore = null): ?string
    {
        $overlapping = static::query()
            ->where('project_id', $projectId)
            ->whereDate('starts_on', '<=', $end)
            ->whereDate('ends_on', '>=', $start)
            ->when($ignore?->exists, fn (Builder $query) => $query->whereKeyNot($ignore->getKey()))
            ->first();

        return $overlapping ? "This site already has the {$overlapping->label()} muster roll covering these days." : null;
    }

    /**
     * The submitted or approved roll that locks a site's attendance on a date, if any.
     */
    public static function lockedFor(int $projectId, CarbonInterface|string $date): ?self
    {
        $date = Carbon::parse($date)->toDateString();

        return static::query()
            ->where('project_id', $projectId)
            ->whereIn('status', ['submitted', 'approved'])
            ->whereDate('starts_on', '<=', $date)
            ->whereDate('ends_on', '>=', $date)
            ->first();
    }

    public function isLocked(): bool
    {
        return in_array($this->status, ['submitted', 'approved'], true);
    }

    public function isEditable(): bool
    {
        return ! $this->isLocked();
    }

    // ── Part I: nominal roll ──────────────────────────────────────────────────

    /**
     * Recreate Part I from the site's labour attendance in this period.
     * Arrear reasons and remarks typed on earlier lines are kept.
     */
    public function rebuildLines(): void
    {
        $dayNumbers = collect($this->dayDates())->mapWithKeys(fn (Carbon $date, int $day): array => [$date->toDateString() => $day]);

        $attendance = LabourAttendance::query()
            ->where('project_id', $this->project_id)
            ->whereDate('date', '>=', $this->starts_on)
            ->whereDate('date', '<=', $this->ends_on)
            ->orderBy('date')
            ->get()
            ->groupBy('labourer_id');

        DB::transaction(function () use ($attendance, $dayNumbers): void {
            $kept = $this->lines()->get()->keyBy('labourer_id');
            $this->lines()->whereNotIn('labourer_id', $attendance->keys())->delete();

            foreach ($attendance as $labourerId => $entries) {
                /** @var EloquentCollection<int, LabourAttendance> $entries */
                $latest = $entries->last();

                $this->lines()->updateOrCreate(['labourer_id' => $labourerId], [
                    'work_type' => $latest->work_type,
                    'days' => $entries->mapWithKeys(fn (LabourAttendance $entry): array => [
                        $dayNumbers[$entry->date->toDateString()] => $entry->code(),
                    ])->all(),
                    'present_days' => $entries->sum(fn (LabourAttendance $entry): float => $entry->dayFactor()),
                    'overtime_hours' => $entries->sum(fn (LabourAttendance $entry): float => (float) $entry->overtime_hours),
                    // A rate change mid-month shows the latest rate; the total is always the true sum.
                    'wage_rate' => $latest->wage_rate,
                    'total_wage' => round($entries->sum(fn (LabourAttendance $entry): float => $entry->wage()), 2),
                    'arrear_reason' => $kept[$labourerId]->arrear_reason ?? null,
                    'remarks' => $kept[$labourerId]->remarks ?? null,
                ]);
            }
        });
    }

    public function totalWage(): float
    {
        return round((float) $this->lines()->sum('total_wage'), 2);
    }

    // ── Part II: arrears ──────────────────────────────────────────────────────

    /**
     * How much of each line has been paid. A labourer has one account across all sites, so
     * everything paid to them (advances included, returned advances deducted) fills their
     * approved months at any site oldest first; this roll counts too while it is being prepared.
     *
     * @return array<int, float> line id => paid
     */
    public function paidByLine(): array
    {
        $labourerIds = $this->lines()->pluck('labourer_id');

        $lines = MusterRollLine::query()
            ->whereIn('labourer_id', $labourerIds)
            ->whereHas('musterRoll', fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->where('status', 'approved')->orWhereKey($this->getKey())))
            ->join('muster_rolls', 'muster_rolls.id', '=', 'muster_roll_lines.muster_roll_id')
            ->orderBy('muster_rolls.starts_on')
            ->orderBy('muster_rolls.id')
            ->select('muster_roll_lines.*')
            ->get();

        $pool = WagePayment::query()
            ->whereIn('labourer_id', $labourerIds)
            ->groupBy('labourer_id')
            ->selectRaw('labourer_id, SUM(amount) as total')
            ->pluck('total', 'labourer_id')
            ->map(fn ($total): float => (float) $total)
            ->all();

        $paid = [];

        foreach ($lines as $line) {
            $available = $pool[$line->labourer_id] ?? 0.0;
            $paid[$line->id] = round(min((float) $line->total_wage, $available), 2);
            $pool[$line->labourer_id] = $available - $paid[$line->id];
        }

        return $paid;
    }

    /**
     * Part II rows: unpaid wages on this roll and on this site's earlier approved rolls
     * (a labourer's unpaid months at other sites are printed on those sites' rolls).
     *
     * @return list<array{line: MusterRollLine, month: string, due: float, paid: float, unpaid: float}>
     */
    public function arrears(): array
    {
        $paid = $this->paidByLine();

        return MusterRollLine::query()
            ->whereKey(array_keys($paid))
            ->with(['labourer', 'musterRoll'])
            ->get()
            ->filter(fn (MusterRollLine $line): bool => (int) $line->musterRoll->project_id === (int) $this->project_id && $line->musterRoll->starts_on->lte($this->starts_on))
            ->map(fn (MusterRollLine $line): array => [
                'line' => $line,
                'month' => $line->musterRoll->label(),
                'due' => (float) $line->total_wage,
                'paid' => $paid[$line->id],
                'unpaid' => round((float) $line->total_wage - $paid[$line->id], 2),
            ])
            ->filter(fn (array $row): bool => $row['unpaid'] > 0)
            ->sortBy(fn (array $row): string => $row['line']->musterRoll->starts_on->toDateString().'-'.$row['line']->labourer->name)
            ->values()
            ->all();
    }

    // ── Workflow ──────────────────────────────────────────────────────────────

    public function submit(User $by): void
    {
        if ($this->isLocked()) {
            return;
        }

        $this->rebuildLines();

        if (! $this->lines()->exists()) {
            throw ValidationException::withMessages(['status' => 'There is no labour attendance in this period yet.']);
        }

        $this->forceFill(['status' => 'submitted', 'submitted_at' => now(), 'prepared_by' => $this->prepared_by ?? $by->getKey(), 'review_note' => null])->save();

        Alerts::musterRollSubmitted($this);
    }

    public function approve(User $by, ?string $note = null): void
    {
        $this->forceFill(['status' => 'approved', 'approved_by' => $by->getKey(), 'approved_at' => now(), 'review_note' => $note])->save();

        Alerts::musterRollReviewed($this);
    }

    public function returnForChanges(User $by, string $note): void
    {
        $this->forceFill(['status' => 'returned', 'approved_by' => null, 'approved_at' => null, 'review_note' => $note])->save();

        Alerts::musterRollReviewed($this);
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(MusterRollLine::class);
    }

    public function works(): HasMany
    {
        return $this->hasMany(MusterRollWork::class)->orderBy('sort');
    }

    public function wagePayments(): HasMany
    {
        return $this->hasMany(WagePayment::class);
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
