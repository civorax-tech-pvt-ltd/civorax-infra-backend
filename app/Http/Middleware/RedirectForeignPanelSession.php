<?php

namespace App\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RedirectForeignPanelSession
{
    /**
     * If a user is authenticated but doesn't belong to the panel they're
     * currently visiting (e.g. an admin session hitting /student/*), log
     * them out and send them to that panel's own login page instead of
     * letting Filament throw a bare 403.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Filament::auth()->user();

        if ($user && ! $user->canAccessPanel(Filament::getCurrentPanel())) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect(Filament::getLoginUrl() ?? '/');
        }

        return $next($request);
    }
}
