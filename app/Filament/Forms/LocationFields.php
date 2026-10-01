<?php

namespace App\Filament\Forms;

use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Illuminate\Support\HtmlString;

/**
 * GPS coordinates + attendance radius for a place (project site or office).
 */
class LocationFields
{
    public static function make(int $defaultRadius, bool $required = false, string $description = '', string $title = 'Location for attendance', bool $withRadius = true): Section
    {
        return Section::make($title)
            ->description($description)
            ->collapsible()
            ->columns(3)
            ->schema([
                TextInput::make('paste_coordinates')
                    ->label('Paste coordinates')
                    ->placeholder('e.g. 26.6621, 87.4478')
                    ->helperText('In Google Maps, long-press the spot and copy the numbers shown.')
                    ->dehydrated(false)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (?string $state, Set $set): void {
                        if (preg_match('/(-?\d{1,2}\.\d+)\s*,\s*(-?\d{1,3}\.\d+)/', (string) $state, $matches)) {
                            $set('latitude', round((float) $matches[1], 7));
                            $set('longitude', round((float) $matches[2], 7));
                            $set('paste_coordinates', null);
                        }
                    })
                    ->columnSpanFull(),
                TextInput::make('latitude')
                    ->numeric()
                    ->minValue(-90)
                    ->maxValue(90)
                    ->required($required)
                    ->requiredWith('longitude')
                    ->live(onBlur: true),
                TextInput::make('longitude')
                    ->numeric()
                    ->minValue(-180)
                    ->maxValue(180)
                    ->required($required)
                    ->requiredWith('latitude')
                    ->live(onBlur: true),
                TextInput::make('geofence_radius')
                    ->label('Attendance radius')
                    ->numeric()
                    ->integer()
                    ->minValue(50)
                    ->maxValue(5000)
                    ->default($defaultRadius)
                    ->required()
                    ->suffix('m')
                    ->visible($withRadius),
                Actions::make([
                    Action::make('useCurrentLocation')
                        ->label('Use my current location')
                        ->icon('heroicon-o-map-pin')
                        ->color('gray')
                        ->alpineClickHandler(<<<'JS'
                            if (! window.isSecureContext || ! navigator.geolocation) { alert('Location needs a secure (https) connection.'); return; }
                            navigator.geolocation.getCurrentPosition(
                                (p) => { $wire.set('data.latitude', p.coords.latitude.toFixed(7)); $wire.set('data.longitude', p.coords.longitude.toFixed(7)); },
                                () => alert('Could not read your location. Allow location access and try again.'),
                                { enableHighAccuracy: true, timeout: 20000 },
                            )
                            JS),
                ])->columnSpan(1),
                Placeholder::make('map_link')
                    ->hiddenLabel()
                    ->content(fn (Get $get): ?HtmlString => filled($get('latitude')) && filled($get('longitude'))
                        ? new HtmlString('<a href="https://www.google.com/maps?q='.(float) $get('latitude').','.(float) $get('longitude').'" target="_blank" rel="noopener" style="text-decoration: underline;">Open in Google Maps ↗</a>')
                        : null)
                    ->columnSpan(2),
            ]);
    }
}
