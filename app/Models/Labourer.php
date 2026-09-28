<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable([
    'name', 'father_name', 'citizenship_no', 'phone', 'address', 'photo_path',
    'work_type', 'daily_wage', 'labour_contractor_id', 'is_active', 'created_by',
])]
class Labourer extends Model
{
    use LogsActivity, SoftDeletes;

    /**
     * @var array<string, string>
     */
    public const WORK_TYPES = [
        'mason' => 'Mason (Dakarmi)',
        'helper' => 'Helper (Jyami)',
        'carpenter' => 'Carpenter (Sikarmi)',
        'bar_bender' => 'Bar bender',
        'painter' => 'Painter',
        'plumber' => 'Plumber',
        'electrician' => 'Electrician',
        'welder' => 'Welder',
        'tile_fitter' => 'Tile fitter',
        'operator' => 'Machine operator',
        'watchman' => 'Watchman (Chaukidar)',
        'other' => 'Other',
    ];

    protected function casts(): array
    {
        return [
            'daily_wage' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnlyDirty();
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function workTypeLabel(): string
    {
        return self::WORK_TYPES[$this->work_type] ?? (string) $this->work_type;
    }

    /**
     * "Ram Bahadur (Mason) · s/o Hari" so same-name labourers can be told apart in pickers.
     */
    public function selectLabel(): string
    {
        return collect([
            "{$this->name} ({$this->workTypeLabel()})",
            $this->father_name ? "s/o {$this->father_name}" : null,
            $this->phone,
        ])->filter()->implode(' · ');
    }

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(LabourContractor::class, 'labour_contractor_id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(LabourAttendance::class);
    }

    public function wagePayments(): HasMany
    {
        return $this->hasMany(WagePayment::class);
    }

    public function musterRollLines(): HasMany
    {
        return $this->hasMany(MusterRollLine::class);
    }

    /**
     * One running account per labourer across all sites (their khata): wages earned on approved
     * muster rolls, minus everything paid (advances included; returned advances are stored negative).
     * Positive = we owe the labourer; negative = the labourer holds an advance that later wages absorb.
     */
    public function balance(): float
    {
        $earned = (float) $this->musterRollLines()
            ->whereHas('musterRoll', fn (Builder $query) => $query->where('status', 'approved'))
            ->sum('total_wage');

        return round($earned - (float) $this->wagePayments()->sum('amount'), 2);
    }

    /**
     * The same balance as an SQL expression on the labourers table, for list columns, filters and sorting.
     */
    public static function balanceSql(): string
    {
        return '((SELECT COALESCE(SUM(muster_roll_lines.total_wage), 0) FROM muster_roll_lines'
            .' INNER JOIN muster_rolls ON muster_rolls.id = muster_roll_lines.muster_roll_id'
            ." WHERE muster_roll_lines.labourer_id = labourers.id AND muster_rolls.status = 'approved')"
            .' - (SELECT COALESCE(SUM(wage_payments.amount), 0) FROM wage_payments WHERE wage_payments.labourer_id = labourers.id))';
    }

    public function scopeWithBalance(Builder $query): void
    {
        $query->addSelect(['labourers.*'])->selectRaw(static::balanceSql().' as account_balance');
    }

    /**
     * The labourer's statement: approved monthly wages (credit) and payments (debit), oldest first,
     * with a running balance. Filtering by site shows that site's entries with a site-only balance.
     *
     * @return array{opening: float, rows: list<array{date: Carbon, site: ?string, entry: string, detail: ?string, credit: float, debit: float, balance: float}>, credit: float, debit: float, closing: float}
     */
    public function ledger(?int $projectId = null, ?string $from = null, ?string $until = null): array
    {
        $fmt = fn ($value): string => rtrim(rtrim(number_format((float) $value, 1), '0'), '.');

        $earned = $this->musterRollLines()
            ->whereHas('musterRoll', fn (Builder $query) => $query->where('status', 'approved'))
            ->with('musterRoll.project')
            ->get()
            ->map(fn (MusterRollLine $line): array => [
                'date' => $line->musterRoll->ends_on,
                'order' => 1,
                'project_id' => $line->musterRoll->project_id,
                'site' => $line->musterRoll->project?->title,
                'entry' => "Wages {$line->musterRoll->label()}",
                'detail' => $fmt($line->present_days).' days'.((float) $line->overtime_hours > 0 ? ' + '.$fmt($line->overtime_hours).' h OT' : '').' @ Rs '.number_format((float) $line->wage_rate),
                'credit' => (float) $line->total_wage,
                'debit' => 0.0,
            ]);

        $paid = $this->wagePayments()
            ->with(['project', 'contractor'])
            ->get()
            ->map(fn (WagePayment $payment): array => [
                'date' => $payment->paid_on,
                'order' => 0,
                'project_id' => $payment->project_id,
                'site' => $payment->project?->title,
                'entry' => WagePayment::TYPES[$payment->type] ?? $payment->type,
                'detail' => collect([
                    WagePayment::METHODS[$payment->method] ?? $payment->method,
                    $payment->reference ? "ref {$payment->reference}" : null,
                    $payment->contractor ? "via {$payment->contractor->name}" : null,
                    $payment->note,
                ])->filter()->implode(' · '),
                // A returned advance is money coming back: it shows on the credit side.
                'credit' => (float) $payment->amount < 0 ? -(float) $payment->amount : 0.0,
                'debit' => (float) $payment->amount > 0 ? (float) $payment->amount : 0.0,
            ]);

        $entries = $earned->concat($paid)
            ->when($projectId, fn ($entries) => $entries->where('project_id', $projectId))
            ->sortBy(fn (array $entry): string => $entry['date']->toDateString().$entry['order'])
            ->values();

        $opening = $from
            ? round($entries->filter(fn (array $entry): bool => $entry['date']->toDateString() < $from)->sum(fn (array $entry): float => $entry['credit'] - $entry['debit']), 2)
            : 0.0;

        $balance = $opening;
        $rows = $entries
            ->filter(fn (array $entry): bool => (! $from || $entry['date']->toDateString() >= $from) && (! $until || $entry['date']->toDateString() <= $until))
            ->map(function (array $entry) use (&$balance): array {
                $balance = round($balance + $entry['credit'] - $entry['debit'], 2);

                return [...collect($entry)->except(['order', 'project_id'])->all(), 'balance' => $balance];
            })
            ->values()
            ->all();

        $credit = round(array_sum(array_column($rows, 'credit')), 2);
        $debit = round(array_sum(array_column($rows, 'debit')), 2);

        return ['opening' => $opening, 'rows' => $rows, 'credit' => $credit, 'debit' => $debit, 'closing' => round($opening + $credit - $debit, 2)];
    }
}
