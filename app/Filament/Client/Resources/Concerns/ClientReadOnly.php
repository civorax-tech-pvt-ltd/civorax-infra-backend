<?php

namespace App\Filament\Client\Resources\Concerns;

use App\Filament\Client\Resources\ProjectResource;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Client portal list pages: read-only, limited to the signed-in client's own projects.
 * (They bypass the admin/team Shield policies, which clients have no permissions for.)
 */
trait ClientReadOnly
{
    public static function canViewAny(): bool
    {
        return auth()->user()?->client !== null;
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /**
     * Restrict a query on a model with a project_id to the client's projects.
     */
    protected static function ownProjects(Builder $query, string $column = 'project_id'): Builder
    {
        return $query->whereIn($column, Project::query()->whereHas('client', fn (Builder $q) => $q->where('user_id', auth()->id()))->select('projects.id'));
    }

    /**
     * Project page link, optionally opening one of its tabs.
     */
    protected static function projectUrl(int|string $projectId, ?int $tab = null): string
    {
        return ProjectResource::getUrl('view', ['record' => $projectId]).($tab === null ? '' : "?activeRelationManager={$tab}");
    }

    /**
     * @return array<int, string>
     */
    protected static function projectOptions(): array
    {
        return Project::query()->whereHas('client', fn (Builder $q) => $q->where('user_id', auth()->id()))->orderBy('title')->pluck('title', 'id')->all();
    }
}
