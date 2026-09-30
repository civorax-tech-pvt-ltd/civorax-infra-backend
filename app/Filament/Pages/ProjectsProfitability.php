<?php

namespace App\Filament\Pages;

use App\Filament\Resources\ProjectResource;
use App\Models\Project;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * Company dashboard: every project's contract value, cost, projected profit, margin, progress and health side by side.
 */
class ProjectsProfitability extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-bar';

    protected static ?string $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Projects Profitability';

    protected static ?string $title = 'Projects Profitability';

    protected static ?string $slug = 'projects-profitability';

    protected static string $view = 'filament.pages.projects-profitability';

    public string $scope = 'active';

    public static function canAccess(): bool
    {
        return ProjectResource::canViewCosts();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(): Collection
    {
        return Project::query()
            ->with('client')
            ->when($this->scope === 'active', fn ($query) => $query->whereNotIn('status', ['completed', 'inquiry']))
            ->when($this->scope === 'completed', fn ($query) => $query->where('status', 'completed'))
            ->orderBy('title')
            ->get()
            ->map(function (Project $project): array {
                $report = $project->costReport();

                return [
                    'project' => $project,
                    'contract' => $report->contractValue(),
                    'budget' => $report->budgetTotal(),
                    'cost' => $report->costSoFar(),
                    'projected_cost' => $report->projectedFinalCost(),
                    'profit' => $report->projectedProfit(),
                    'margin' => $report->projectedMargin(),
                    'staff' => $report->staffCost(),
                    'work' => $report->workComplete()['percent'],
                    'health' => $report->health()['status'],
                    'cash' => $report->cash(),
                    'url' => ProjectResource::getUrl('costs', ['record' => $project]),
                ];
            });
    }
}
