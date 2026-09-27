<?php

namespace App\Filament\Client\Widgets;

use App\Filament\Client\Resources\ProjectResource;
use App\Models\Project;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ClientProjects extends Widget
{
    protected static string $view = 'filament.widgets.project-cards';

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array{heading: string, empty: string, cards: Collection<int, array<string, mixed>>}
     */
    protected function getViewData(): array
    {
        $projects = Project::query()
            ->whereHas('client', fn (Builder $query) => $query->where('user_id', auth()->id()))
            ->with(['projectType', 'milestones'])
            ->latest('updated_at')
            ->get();

        return [
            'heading' => 'My projects',
            'empty' => 'No projects yet. Our team will add your project here once it is set up.',
            'cards' => $projects->map(fn (Project $project): array => [
                'title' => $project->title,
                'meta' => $project->projectType?->name.($project->city ? ' · '.$project->city : ''),
                'status' => Project::STATUSES[$project->status] ?? $project->status,
                'progress' => $project->progress,
                'footer' => ($next = $project->milestones->sortBy('sequence')->firstWhere('status', '!=', 'completed'))
                    ? 'Current stage: '.$next->title
                    : ($project->status === 'completed' ? 'Completed 🎉' : null),
                'extra' => ($due = $project->amountDueNow()) > 0 ? 'Due now: NPR '.number_format($due, 2) : null,
                'url' => ProjectResource::getUrl('view', ['record' => $project]),
            ]),
        ];
    }
}
