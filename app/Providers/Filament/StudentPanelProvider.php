<?php

namespace App\Providers\Filament;

use App\Filament\Auth\PhoneLogin;
use App\Filament\Student\Auth\StudentRegister;
use App\Filament\Widgets\WelcomeBanner;
use App\Http\Controllers\Student\EnrollController;
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
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class StudentPanelProvider extends PanelProvider
{
    use AppliesBranding;

    public function panel(Panel $panel): Panel
    {
        return $this->applyBranding($panel, Color::Emerald, 'Academy')
            ->id('student')
            ->path('student')
            ->login(PhoneLogin::class)
            ->registration(StudentRegister::class)
            ->discoverResources(in: app_path('Filament/Student/Resources'), for: 'App\\Filament\\Student\\Resources')
            ->discoverPages(in: app_path('Filament/Student/Pages'), for: 'App\\Filament\\Student\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Student/Widgets'), for: 'App\\Filament\\Student\\Widgets')
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
            ])
            ->authenticatedRoutes(function (Panel $panel) {
                Route::get('/enroll/{course}', EnrollController::class)->name('enroll');
            });
    }
}
