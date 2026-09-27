<?php

namespace App\Filament\Resources;

use App\Filament\Forms\LocationFields;
use App\Filament\Resources\OfficeLocationResource\Pages;
use App\Models\OfficeLocation;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class OfficeLocationResource extends Resource
{
    protected static ?string $model = OfficeLocation::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office';

    protected static ?string $navigationGroup = 'Team';

    protected static ?int $navigationSort = 4;

    public static function canAccess(): bool
    {
        return Filament::getCurrentPanel()?->getId() === 'admin';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('e.g. Head office, Belbari'),
                Forms\Components\TextInput::make('address')
                    ->maxLength(255),
                Forms\Components\Toggle::make('is_active')
                    ->label('Counts for attendance')
                    ->default(true),
                LocationFields::make(
                    defaultRadius: 300,
                    required: true,
                    description: 'Any team member within this distance of the office is marked present.',
                )->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('address')->placeholder('—'),
                Tables\Columns\TextColumn::make('coordinates')
                    ->state(fn (OfficeLocation $record): string => "{$record->latitude}, {$record->longitude}")
                    ->url(fn (OfficeLocation $record): string => "https://www.google.com/maps?q={$record->latitude},{$record->longitude}", shouldOpenInNewTab: true),
                Tables\Columns\TextColumn::make('geofence_radius')->label('Radius')->suffix(' m'),
                Tables\Columns\IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOfficeLocations::route('/'),
            'create' => Pages\CreateOfficeLocation::route('/create'),
            'edit' => Pages\EditOfficeLocation::route('/{record}/edit'),
        ];
    }
}
