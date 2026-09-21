<?php

namespace App\Filament\Auth\Concerns;

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;

trait LogsOutForeignPanelSession
{
    /**
     * If the browser is already authenticated as a user who doesn't belong to
     * THIS panel (e.g. logged into /admin, now visiting /student), log them out
     * first so they see a normal login/register form instead of a 403.
     */
    protected function logoutIfWrongPanel(): void
    {
        $user = Filament::auth()->user();

        if ($user && ! $user->canAccessPanel(Filament::getCurrentPanel())) {
            Auth::guard('web')->logout();
            request()->session()->invalidate();
            request()->session()->regenerateToken();
        }
    }
}
