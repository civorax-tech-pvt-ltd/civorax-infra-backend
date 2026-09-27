<?php

namespace App\Filament\Client\Widgets;

use App\Models\Project;
use App\Models\ProjectPaymentSubmission;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

class ClientStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $projects = Project::query()->whereHas('client', fn (Builder $query) => $query->where('user_id', auth()->id()))->get();
        $dueNow = $projects->sum(fn (Project $project): float => (float) $project->amountDueNow());
        $pending = ProjectPaymentSubmission::query()->whereIn('project_id', $projects->modelKeys())->where('status', 'pending')->sum('amount');

        return [
            Stat::make('Active projects', $projects->where('status', '!=', 'completed')->count())
                ->description($projects->where('status', 'completed')->count().' completed')
                ->descriptionIcon('heroicon-m-building-office-2')
                ->color('primary'),
            Stat::make('Contract value', 'NPR '.number_format((float) $projects->sum('fee')))
                ->description('Agreed fees')
                ->descriptionIcon('heroicon-m-document-check')
                ->color('gray'),
            Stat::make('Paid', 'NPR '.number_format($projects->sum(fn (Project $project): float => $project->amountPaid())))
                ->description($pending > 0 ? 'NPR '.number_format((float) $pending).' awaiting verification' : 'Thank you!')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success'),
            Stat::make('Due now', 'NPR '.number_format($dueNow))
                ->description($dueNow > 0 ? 'For completed milestones' : 'Nothing due right now')
                ->descriptionIcon($dueNow > 0 ? 'heroicon-m-exclamation-circle' : 'heroicon-m-face-smile')
                ->color($dueNow > 0 ? 'danger' : 'success'),
        ];
    }
}
