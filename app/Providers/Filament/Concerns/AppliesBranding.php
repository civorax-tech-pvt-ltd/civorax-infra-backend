<?php

namespace App\Providers\Filament\Concerns;

use Filament\Panel;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\MaxWidth;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Blade;

/**
 * The CivoraX look shared by every panel: logo, font, palette, layout and light styling.
 */
trait AppliesBranding
{
    /**
     * @param  array<int, string>  $primary  a Filament Color palette, e.g. Color::Amber
     */
    protected function applyBranding(Panel $panel, array $primary, string $portalName): Panel
    {
        return $panel
            ->brandName('CivoraX Infra')
            ->brandLogo(fn () => view('filament.brand', ['portalName' => $portalName]))
            ->brandLogoHeight('2.5rem')
            ->favicon(asset('favicon.ico'))
            ->font('Plus Jakarta Sans')
            ->colors([
                'primary' => $primary,
                'gray' => Color::Slate,
                'danger' => Color::Rose,
                'info' => Color::Sky,
                'success' => Color::Emerald,
                'warning' => Color::Amber,
            ])
            // Page changes swap the content in place (no full browser reload). Print pages, the offline
            // site app, certificates and uploaded files are full pages of their own, so they load normally.
            ->spa()
            ->spaUrlExceptions(fn (): array => [
                url('/site/*'),
                url('/certificates/*'),
                url('/verify/*'),
                url('/storage/*'),
            ])
            ->sidebarCollapsibleOnDesktop()
            ->maxContentWidth(MaxWidth::Full)
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => view('filament.theme-styles'))
            ->renderHook(PanelsRenderHook::BODY_END, fn (): string => auth()->check() ? Blade::render('<livewire:desktop-alerts />') : '');
    }
}
