<?php

namespace App\Providers\Filament;

use App\Filament\Auth\PhoneLogin;
use App\Filament\Widgets\WelcomeBanner;
use App\Http\Middleware\EnsureSingleSession;
use App\Http\Middleware\RedirectForeignPanelSession;
use App\Providers\Filament\Concerns\AppliesBranding;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class TeamPanelProvider extends PanelProvider
{
    use AppliesBranding;

    public function panel(Panel $panel): Panel
    {
        return $this->applyBranding($panel, Color::Indigo, 'Team')
            ->id('team')
            ->path('team')
            ->login(PhoneLogin::class)
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Team/Widgets'), for: 'App\\Filament\\Team\\Widgets')
            ->widgets([
                WelcomeBanner::class,
            ])
            ->navigationGroups([
                'CRM',
                'Projects',
                'Finance',
                'Academy',
                'Team',
            ])
            ->collapsibleNavigationGroups(true)
            ->renderHook(
                PanelsRenderHook::USER_MENU_BEFORE,
                fn (): string => auth()->user()?->teamMember ? Blade::render('<livewire:attendance-tracker />') : '',
            )
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                RedirectForeignPanelSession::class,
                EnsureSingleSession::class,
            ]);
    }
}
