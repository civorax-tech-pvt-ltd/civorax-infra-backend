<?php

namespace App\Filament\Team\Widgets;

use App\Models\Attendance;
use App\Models\AttendanceMonthlySummary;
use App\Models\Project;
use App\Models\Task;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

class MyWorkStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    public static function canView(): bool
    {
        return auth()->user()?->teamMember !== null;
    }

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $teamMember = auth()->user()->teamMember;
        $openTasks = Task::query()->involving($teamMember)->where('status', '!=', 'completed');
        $overdue = $openTasks->clone()->overdue()->count();
        $month = AttendanceMonthlySummary::query()
            ->where('team_member_id', $teamMember->id)
            ->whereDate('month', Carbon::parse(Attendance::businessToday())->startOfMonth()->toDateString())
            ->first();

        return [
            Stat::make('Open tasks', $openTasks->clone()->count())
                ->description($overdue > 0 ? "{$overdue} overdue" : 'Nothing overdue')
                ->descriptionIcon($overdue > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
                ->color($overdue > 0 ? 'danger' : 'success'),
            Stat::make('Due this week', $openTasks->clone()->whereBetween('due_at', [now(), now()->addDays(7)])->count())
                ->description('Next 7 days')
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color('warning'),
            Stat::make('My projects', Project::query()->visibleToTeamMember($teamMember)->active()->count())
                ->description('Active projects I work on')
                ->descriptionIcon('heroicon-m-building-office-2')
                ->color('primary'),
            Stat::make('Days present', $month?->present_days ?? 0)
                ->description(($month?->total_hours ?? 0).' h this month')
                ->descriptionIcon('heroicon-m-map-pin')
                ->color('info'),
        ];
    }
}
