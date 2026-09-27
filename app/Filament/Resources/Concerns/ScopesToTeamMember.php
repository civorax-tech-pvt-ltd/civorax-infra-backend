<?php

namespace App\Filament\Resources\Concerns;

use App\Models\TeamMember;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * In the team panel, limits a shared resource to records the signed-in team member works on.
 * The admin panel is unaffected.
 */
trait ScopesToTeamMember
{
    /**
     * In the team panel, keep super admins out of team member pickers (assignee, helpers, project team).
     */
    public static function limitTeamMemberOptions(Builder $query): Builder
    {
        return Filament::getCurrentPanel()?->getId() === 'team'
            ? $query->withoutSuperAdmins()
            : $query;
    }

    /**
     * Sync a team member relation from a picker, keeping super admins the team panel hid from
     * that picker so a team member's save never drops people an admin added.
     *
     * @param  array<int|string>|null  $state
     * @param  array<int|string|null>  $exclude
     */
    public static function syncTeamMembers(BelongsToMany $relation, ?array $state, array $exclude = []): void
    {
        $hidden = Filament::getCurrentPanel()?->getId() === 'team'
            ? $relation->getQuery()->clone()->whereHas('user.roles', fn (Builder $query) => $query->where('name', 'super_admin'))->pluck('team_members.id')->all()
            : [];

        $relation->sync(array_values(array_diff(array_unique([...($state ?? []), ...$hidden]), array_filter($exclude))));
    }

    /**
     * @param  Closure(Builder, TeamMember): mixed  $scope
     */
    protected static function scopeToTeamMember(Builder $query, Closure $scope): Builder
    {
        if (Filament::getCurrentPanel()?->getId() !== 'team') {
            return $query;
        }

        $teamMember = auth()->user()?->teamMember;

        if ($teamMember === null) {
            return $query->whereRaw('1 = 0');
        }

        $scope($query, $teamMember);

        return $query;
    }
}
