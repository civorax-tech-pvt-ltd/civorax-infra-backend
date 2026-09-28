<?php

namespace App\Filament\Resources\Concerns;

use App\Models\Project;
use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may use the site screens (labour, muster rolls, site reports) and which sites they see.
 * Admin panel: everything. Team panel: team members see their own projects; users whose role
 * was granted "approve_site_records" see and approve every site; "pay_labour_wages" records wages.
 */
trait SiteAccess
{
    protected static function isAdminPanel(): bool
    {
        return Filament::getCurrentPanel()?->getId() === 'admin';
    }

    protected static function siteUser(): ?User
    {
        return auth()->user();
    }

    public static function canUseSite(): bool
    {
        return static::isAdminPanel() || static::siteUser()?->teamMember !== null;
    }

    public static function canApproveSite(): bool
    {
        return (bool) static::siteUser()?->hasSitePower('approve_site_records');
    }

    public static function canPayWages(): bool
    {
        return (bool) static::siteUser()?->hasSitePower('pay_labour_wages');
    }

    /**
     * Labourer balances and ledgers: people who approve or pay wages.
     */
    public static function canSeeWages(): bool
    {
        return static::canPayWages() || static::canApproveSite();
    }

    /**
     * Limit a query of site records (with a project_id column) to the sites the user may see.
     */
    public static function scopeToSites(Builder $query, string $column = 'project_id'): Builder
    {
        if (static::isAdminPanel()) {
            return $query;
        }

        return $query->whereIn($column, static::siteProjectQuery()->select('projects.id'));
    }

    /**
     * Validation for a project picker: the chosen site must be one the user may use (pickers can be tampered with).
     */
    public static function siteProjectRule(): Closure
    {
        // Filament evaluates closure rules first, so the validation rule itself is returned from one.
        return fn (): Closure => function (string $attribute, $value, Closure $fail): void {
            if (filled($value) && ! static::siteProjectQuery()->whereKey($value)->exists()) {
                $fail('You are not on this project\'s team.');
            }
        };
    }

    /**
     * Projects offered in site pickers.
     */
    public static function siteProjectQuery(?Builder $query = null): Builder
    {
        $query ??= Project::query();

        return static::isAdminPanel() ? $query : $query->siteAccessibleBy(static::siteUser());
    }
}
