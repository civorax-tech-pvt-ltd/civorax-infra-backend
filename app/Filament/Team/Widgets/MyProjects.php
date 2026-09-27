<?php

namespace App\Filament\Team\Widgets;

use App\Filament\Resources\ProjectResource;
use App\Models\Project;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

class MyProjects extends Widget
{
    protected static string $view = 'filament.widgets.project-cards';

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->teamMember !== null;
    }

    /**
     * @return array{heading: string, empty: string, cards: Collection<int, array<string, mixed>>}
     */
    protected function getViewData(): array
    {
        $projects = Project::query()
            ->visibleToTeamMember(auth()->user()->teamMember)
            ->where('status', '!=', 'completed')
            ->with(['client', 'milestones'])
            ->latest('updated_at')
            ->get();

        return [
            'heading' => 'My projects',
            'empty' => 'You are not assigned to any active project yet.',
            'cards' => $projects->map(fn (Project $project): array => [
                'title' => $project->title,
                'meta' => $project->client?->contact_person.($project->city ? ' · '.$project->city : ''),
                'status' => Project::STATUSES[$project->status] ?? $project->status,
                'progress' => $project->progress,
                'footer' => ($next = $project->milestones->sortBy('sequence')->firstWhere('status', '!=', 'completed'))
                    ? 'Now: '.$next->title
                    : null,
                'url' => ProjectResource::getUrl(auth()->user()->can('update', $project) ? 'edit' : 'index', auth()->user()->can('update', $project) ? ['record' => $project] : []),
            ]),
        ];
    }
}
