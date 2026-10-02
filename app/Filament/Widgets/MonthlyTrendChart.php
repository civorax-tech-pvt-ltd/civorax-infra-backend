<?php

namespace App\Filament\Widgets;

use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * New records per month (bars) with the running total (line). Always on the admin dashboard; on the team
 * dashboard for roles chosen under Approval Settings › Dashboard charts.
 */
abstract class MonthlyTrendChart extends ChartWidget
{
    protected static ?string $maxHeight = '300px';

    protected int|string|array $columnSpan = 'full';

    public ?string $filter = '12';

    /**
     * Records counted, by their created_at.
     */
    abstract protected function query(): Builder;

    /**
     * The permission (Approval Settings › Dashboard charts) that shows this chart on the team dashboard.
     */
    abstract protected static function permission(): string;

    abstract protected function noun(): string;

    /**
     * @return array{0: string, 1: string} bar colour, line colour (r, g, b)
     */
    protected function colors(): array
    {
        return ['245, 158, 11', '14, 165, 233'];
    }

    public static function canView(): bool
    {
        return match (Filament::getCurrentPanel()?->getId()) {
            'admin' => true,
            'team' => (bool) auth()->user()?->hasSitePower(static::permission()),
            default => false,
        };
    }

    public function getDescription(): ?string
    {
        $thisMonth = $this->query()->where('created_at', '>=', now()->startOfMonth())->count();
        $lastMonth = $this->query()->whereBetween('created_at', [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()])->count();

        return "{$thisMonth} this month · {$lastMonth} last month";
    }

    protected function getFilters(): ?array
    {
        return [
            '6' => 'Last 6 months',
            '12' => 'Last 12 months',
            '24' => 'Last 24 months',
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $months = max(1, (int) $this->filter);
        $start = now()->startOfMonth()->subMonthsNoOverflow($months - 1);

        // Counted in PHP so it works the same on MySQL and SQLite.
        $perMonth = $this->query()
            ->where('created_at', '>=', $start)
            ->pluck('created_at')
            ->countBy(fn (Carbon $date): string => $date->format('Y-m'));

        // The running total includes everything from before the chart starts.
        $running = $this->query()->where('created_at', '<', $start)->count();
        $labels = $counts = $totals = [];

        for ($month = $start->copy(); $month <= now(); $month->addMonthNoOverflow()) {
            $count = $perMonth[$month->format('Y-m')] ?? 0;
            $running += $count;

            $labels[] = $month->format($months > 12 ? 'M Y' : 'M');
            $counts[] = $count;
            $totals[] = $running;
        }

        [$bar, $line] = $this->colors();

        return [
            'datasets' => [
                [
                    'label' => 'New '.$this->noun(),
                    'data' => $counts,
                    'backgroundColor' => "rgba({$bar}, 0.55)",
                    'borderColor' => "rgb({$bar})",
                    'borderWidth' => 1,
                    'borderRadius' => 4,
                    'yAxisID' => 'y',
                    'order' => 2,
                ],
                [
                    'type' => 'line',
                    'label' => 'Total so far',
                    'data' => $totals,
                    'borderColor' => "rgb({$line})",
                    'backgroundColor' => "rgba({$line}, 0.12)",
                    'fill' => true,
                    'tension' => 0.3,
                    'pointRadius' => 3,
                    'yAxisID' => 'total',
                    'order' => 1,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => true, 'position' => 'bottom']],
            'scales' => [
                'y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0], 'title' => ['display' => true, 'text' => 'Per month']],
                'total' => ['beginAtZero' => true, 'position' => 'right', 'grid' => ['drawOnChartArea' => false], 'ticks' => ['precision' => 0], 'title' => ['display' => true, 'text' => 'Total']],
            ],
        ];
    }
}
