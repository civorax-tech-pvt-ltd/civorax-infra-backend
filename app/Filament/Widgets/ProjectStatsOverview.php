<?php

namespace App\Filament\Widgets;

use App\Models\Inquiry;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Task;
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

    protected function getStats(): array
    {
        $activeProjects = Project::query()->active()->get();
        $behindSchedule = $activeProjects->filter->isBehindSchedule()->count();

        $contracted = (float) Project::query()->whereNotNull('fee')->sum('fee');
        $received = (float) Payment::query()->whereHas('project', fn (Builder $query) => $query->whereNotNull('fee'))->sum('amount');

        return [
            Stat::make('Active projects', $activeProjects->count())
                ->description(Project::query()->where('status', 'inquiry')->count().' still at inquiry'),
            Stat::make('Behind schedule', $behindSchedule)
                ->description('Progress below time elapsed')
                ->color($behindSchedule > 0 ? 'danger' : 'success'),
            Stat::make('Overdue tasks', Task::query()->overdue()->count())
                ->color('warning'),
            Stat::make('Collected this month', 'NPR '.number_format((float) Payment::query()
                ->whereBetween('received_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->sum('amount')))
                ->color('success'),
            Stat::make('Outstanding', 'NPR '.number_format(max(0, $contracted - $received)))
                ->description('Agreed fees not yet received'),
            Stat::make('New inquiries', Inquiry::query()->where('created_at', '>=', now()->subDays(7))->count())
                ->description('Last 7 days'),
        ];
    }
}
