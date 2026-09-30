<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Company-level turnover and VAT figures for one Nepali fiscal year (Shrawan–Ashad by default).
 * Turnover here is money received from clients and students (project payments and course fees).
 */
class TaxReport
{
    public readonly CompanySetting $settings;

    public readonly Carbon $start;

    public readonly Carbon $end;

    /**
     * @param  int  $fiscalYear  B.S. year the fiscal year starts in (2083 = FY 2083/84)
     */
    public function __construct(public readonly int $fiscalYear)
    {
        $this->settings = CompanySetting::current();
        $startMonth = $this->settings->fiscal_year_start_month ?: 4;

        [$this->start] = MusterRoll::periodFor('bs', $fiscalYear, $startMonth);
        [$nextStart] = MusterRoll::periodFor('bs', $fiscalYear + 1, $startMonth);
        $this->end = $nextStart->copy()->subDay();
    }

    /**
     * The fiscal year (by its starting B.S. year) that contains a date.
     */
    public static function fiscalYearOf(CarbonInterface|string|null $date = null): int
    {
        [$year, $month] = MusterRoll::toBs($date ?? now(config('app.business_timezone')));

        return $month >= (CompanySetting::current()->fiscal_year_start_month ?: 4) ? $year : $year - 1;
    }

    public function label(): string
    {
        return "FY {$this->fiscalYear}/".substr((string) ($this->fiscalYear + 1), -2);
    }

    public function receipts(): float
    {
        return round(
            (float) Payment::query()->whereDate('received_at', '>=', $this->start)->whereDate('received_at', '<=', $this->end)->sum('amount')
            + (float) CoursePayment::query()->whereDate('received_at', '>=', $this->start)->whereDate('received_at', '<=', $this->end)->sum('amount'),
            2,
        );
    }

    /**
     * Receipts so far plus everything still owed on signed contracts, as if all of it arrives this fiscal year.
     */
    public function projectedYearEnd(): float
    {
        $outstanding = Project::query()
            ->whereNotNull('fee')
            ->whereNotIn('status', ['inquiry', 'on_hold'])
            ->get()
            ->sum(fn (Project $project): float => max(0, (float) $project->balanceDue()));

        return round($this->receipts() + $outstanding, 2);
    }

    public function limit(): ?float
    {
        return $this->settings->vat_registration_limit !== null ? (float) $this->settings->vat_registration_limit : null;
    }

    /**
     * ok / warning (at the set % of the limit) / over, for actual receipts and for the projection.
     *
     * @return array{actual: ?string, projected: ?string}
     */
    public function limitStatus(): array
    {
        $check = function (float $amount): ?string {
            $limit = $this->limit();

            return match (true) {
                $limit === null || $limit <= 0 => null,
                $amount >= $limit => 'over',
                $amount >= $limit * ($this->settings->vat_warning_percent ?: 80) / 100 => 'warning',
                default => 'ok',
            };
        };

        return ['actual' => $check($this->receipts()), 'projected' => $check($this->projectedYearEnd())];
    }

    /**
     * Purchases on VAT bills this year: rising VAT-bill buying is an early sign that registering may pay off.
     */
    public function vatBillPurchases(): float
    {
        return round((float) PurchaseBill::query()->approved()->where('bill_type', 'vat')
            ->whereDate('bill_date', '>=', $this->start)->whereDate('bill_date', '<=', $this->end)
            ->sum('total_amount'), 2);
    }

    /**
     * Per B.S. month: output VAT (inside client receipts on/after registration), input VAT claimed
     * (claimable supplier bills), net payable, and credit carried forward. Empty while PAN-only.
     *
     * @return list<array{month: string, output: float, input: float, net: float, payable: float, credit: float}>
     */
    public function vatSummary(): array
    {
        if (! $this->settings->vat_registered || $this->settings->vat_registration_date === null) {
            return [];
        }

        $rate = (float) $this->settings->vat_rate;
        $from = $this->settings->vat_registration_date;
        $credit = 0.0;
        $rows = [];
        $startMonth = $this->settings->fiscal_year_start_month ?: 4;

        for ($i = 0; $i < 12; $i++) {
            $month = ($startMonth + $i - 1) % 12 + 1;
            $year = $this->fiscalYear + intdiv($startMonth + $i - 1, 12);
            [$start, $end] = MusterRoll::periodFor('bs', $year, $month);

            if ($start->gt(now(config('app.business_timezone')))) {
                break; // months not started yet
            }

            if ($end->lt($from)) {
                continue;
            }

            $start = $start->max($from);
            $received = (float) Payment::query()->whereDate('received_at', '>=', $start)->whereDate('received_at', '<=', $end)->sum('amount')
                + (float) CoursePayment::query()->whereDate('received_at', '>=', $start)->whereDate('received_at', '<=', $end)->sum('amount');
            $output = round($received * $rate / (100 + $rate), 2);
            $input = round((float) PurchaseBill::query()->approved()->where('vat_claimable', true)
                ->whereDate('bill_date', '>=', $start)->whereDate('bill_date', '<=', $end)->sum('vat_amount'), 2);

            $net = round($output - $input, 2);
            $afterCredit = round($net - $credit, 2);
            $payable = max(0.0, $afterCredit);
            $credit = max(0.0, -$afterCredit);

            $rows[] = ['month' => MusterRoll::BS_MONTHS[$month]." {$year}", 'output' => $output, 'input' => $input, 'net' => $net, 'payable' => $payable, 'credit' => $credit];
        }

        return $rows;
    }
}
