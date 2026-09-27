<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\ProjectResource;
use App\Models\Project;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

class BehindScheduleProjects extends Widget
{
    protected static string $view = 'filament.widgets.behind-schedule-projects';

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return Filament::getCurrentPanel()?->getId() === 'admin';
    }

    /**
     * @return Collection<int, array{project: Project, expected: int, url: string}>
     */
    public function getProjects(): Collection
    {
        return Project::query()
            ->active()
            ->whereNotNull('estimated_end_date')
            ->with('client')
            ->get()
            ->filter->isBehindSchedule()
            ->map(fn (Project $project): array => [
                'project' => $project,
                'expected' => $project->expectedProgress(),
                'url' => ProjectResource::getUrl('edit', ['record' => $project]),
            ])
            ->sortByDesc(fn (array $row): int => $row['expected'] - $row['project']->progress)
            ->values();
    }
}
