<?php

namespace App\Filament\Resources\Concerns;

use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

/**
 * For resources without a policy: super admins can do everything (admin panel); in the team panel each action
 * follows the role's ticks in Shield › Roles (e.g. "update_portfolio::project").
 *
 * The using resource sets `protected static string $shieldPermission = 'portfolio::project';`.
 */
trait ShieldPermissions
{
    public static function shieldAllows(string $action): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        if (Filament::getCurrentPanel()?->getId() === 'admin' || $user->hasRole('super_admin')) {
            return true;
        }

        return $user->can("{$action}_".static::$shieldPermission);
    }

    public static function canAccess(): bool
    {
        return static::shieldAllows('view_any');
    }

    public static function canViewAny(): bool
    {
        return static::shieldAllows('view_any');
    }

    public static function canCreate(): bool
    {
        return static::shieldAllows('create');
    }

    public static function canEdit(Model $record): bool
    {
        return static::shieldAllows('update');
    }

    public static function canDelete(Model $record): bool
    {
        return static::shieldAllows('delete');
    }

    public static function canDeleteAny(): bool
    {
        return static::shieldAllows('delete_any');
    }

    public static function canRestore(Model $record): bool
    {
        return static::shieldAllows('restore');
    }

    public static function canForceDelete(Model $record): bool
    {
        return static::shieldAllows('force_delete');
    }

    public static function canReorder(): bool
    {
        return static::shieldAllows('reorder');
    }
}
