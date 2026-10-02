<?php

namespace App\Providers\Filament;

use App\Filament\Auth\PhoneLogin;
use App\Http\Middleware\EnsureSingleSession;
use App\Http\Middleware\RedirectForeignPanelSession;
use App\Providers\Filament\Concerns\AppliesBranding;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    use AppliesBranding;

    public function panel(Panel $panel): Panel
    {
        return $this->applyBranding($panel, Color::Amber, 'Admin')
            ->default()
            ->id('admin')
            ->path(config('services.portal.admin_path'))
            ->login(PhoneLogin::class)
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->navigationGroups([
                'CRM',
                'Website',
                'Projects',
                'Site',
                'Finance',
                'Academy',
                'Team',
            ])
            ->collapsibleNavigationGroups(true)
            ->navigationItems([
                NavigationItem::make('Site app (offline)')
                    ->url(fn (): string => route('site.app'), shouldOpenInNewTab: true)
                    ->icon('heroicon-o-device-phone-mobile')
                    ->group('Site')
                    ->sort(0),
            ])
            ->plugin(FilamentShieldPlugin::make())
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
