<?php

namespace App\Providers\Filament;

use App\Filament\Auth\PhoneLogin;
use App\Filament\Auth\RequestPasswordResetByPhone;
use App\Filament\Client\Auth\ClientRegister;
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
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class ClientPanelProvider extends PanelProvider
{
    use AppliesBranding;

    public function panel(Panel $panel): Panel
    {
        return $this->applyBranding($panel, Color::Blue, 'Client portal')
            ->id('client')
            ->path('client')
            ->login(PhoneLogin::class)
            ->passwordReset(RequestPasswordResetByPhone::class)
            ->registration(ClientRegister::class)
            ->discoverResources(in: app_path('Filament/Client/Resources'), for: 'App\\Filament\\Client\\Resources')
            ->discoverPages(in: app_path('Filament/Client/Pages'), for: 'App\\Filament\\Client\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->navigationGroups(['Projects', 'Payments', 'Files'])
            ->discoverWidgets(in: app_path('Filament/Client/Widgets'), for: 'App\\Filament\\Client\\Widgets')
            ->widgets([
                WelcomeBanner::class,
            ])
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
