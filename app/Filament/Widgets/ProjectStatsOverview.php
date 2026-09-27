<?php

namespace App\Filament\Widgets;

use App\Models\Attendance;
use App\Models\Inquiry;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Quotation;
use App\Models\Task;
use App\Models\TeamMember;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

class ProjectStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    public static function canView(): bool
    {
        return Filament::getCurrentPanel()?->getId() === 'admin';
    }

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $activeProjects = Project::query()->active()->get();
        $behindSchedule = $activeProjects->filter->isBehindSchedule()->count();

        $contracted = (float) Project::query()->whereNotNull('fee')->sum('fee');
        $received = (float) Payment::query()->whereHas('project', fn (Builder $query) => $query->whereNotNull('fee'))->sum('amount');
        $overdueTasks = Task::query()->overdue()->count();
        $changeRequests = Quotation::query()->where('status', 'changes_requested')->count();
        $teamSize = TeamMember::query()->withoutSuperAdmins()->count();
        $presentToday = Attendance::query()
            ->whereDate('date', Attendance::businessToday())
            ->whereHas('teamMember', fn (Builder $query) => $query->withoutSuperAdmins())
            ->count();

        return [
            Stat::make('Active projects', $activeProjects->count())
                ->description(Project::query()->where('status', 'inquiry')->count().' still at inquiry')
                ->descriptionIcon('heroicon-m-building-office-2')
                ->color('primary'),
            Stat::make('Behind schedule', $behindSchedule)
                ->description($behindSchedule > 0 ? 'Progress below time elapsed' : 'All projects on track')
                ->descriptionIcon($behindSchedule > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
                ->color($behindSchedule > 0 ? 'danger' : 'success'),
            Stat::make('Collected this month', 'NPR '.number_format((float) Payment::query()
                ->whereBetween('received_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->sum('amount')))
                ->description('Last 7 days trend')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->chart($this->dailyTotals(Payment::query(), 'received_at', 'amount'))
                ->color('success'),
            Stat::make('Outstanding', 'NPR '.number_format(max(0, $contracted - $received)))
                ->description('Agreed fees not yet received')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('warning'),
            Stat::make('Overdue tasks', $overdueTasks)
                ->description($overdueTasks > 0 ? 'Need attention' : 'Nothing overdue')
                ->descriptionIcon('heroicon-m-clock')
                ->color($overdueTasks > 0 ? 'danger' : 'gray'),
            Stat::make('Team present today', "{$presentToday} / {$teamSize}")
                ->description('Marked by GPS or manually')
                ->descriptionIcon('heroicon-m-map-pin')
                ->color('info'),
            Stat::make('New inquiries', Inquiry::query()->where('created_at', '>=', now()->subDays(7))->count())
                ->description('Last 7 days')
                ->descriptionIcon('heroicon-m-inbox-arrow-down')
                ->chart($this->dailyTotals(Inquiry::query(), 'created_at'))
                ->color('primary'),
            Stat::make('Quotation change requests', $changeRequests)
                ->description('Clients waiting for a revision')
                ->descriptionIcon('heroicon-m-chat-bubble-left-ellipsis')
                ->color($changeRequests > 0 ? 'warning' : 'gray'),
        ];
    }

    /**
     * One value per day for the last 7 days: a count of rows, or the sum of $sumColumn.
     *
     * @return list<float>
     */
    protected function dailyTotals(Builder $query, string $dateColumn, ?string $sumColumn = null): array
    {
        $rows = $query->clone()
            ->where($dateColumn, '>=', now()->subDays(6)->startOfDay())
            ->get([$dateColumn, ...($sumColumn ? [$sumColumn] : [])])
            ->groupBy(fn ($row): string => $row->{$dateColumn}->toDateString());

        return collect(range(6, 0))
            ->map(fn (int $daysAgo): float => (float) (($day = $rows->get(now()->subDays($daysAgo)->toDateString()))
                ? ($sumColumn ? $day->sum($sumColumn) : $day->count())
                : 0))
            ->all();
    }
}
