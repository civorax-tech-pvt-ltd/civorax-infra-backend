<?php

namespace App\Filament\Resources;

use App\Filament\Resources\KeyMaterialResource\Pages;
use App\Models\KeyMaterial;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The short list of materials tracked by quantity (5–8 items is plenty).
 */
class KeyMaterialResource extends Resource
{
    protected static ?string $model = KeyMaterial::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube-transparent';

    protected static ?string $navigationGroup = 'Site';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Key Materials List';

    public static function canAccess(): bool
    {
        return Filament::getCurrentPanel()?->getId() === 'admin';
    }

    public static function canViewAny(): bool
    {
        return static::canAccess();
    }

    public static function canCreate(): bool
    {
        return static::canAccess();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canAccess();
    }

    public static function canDelete(Model $record): bool
    {
        return false; // deactivate instead; history refers to it
    }

    public static function form(Form $form): Form
    {
        return $form->columns(3)->schema([
            Forms\Components\TextInput::make('name')->required()->maxLength(100),
            Forms\Components\TextInput::make('unit')->required()->datalist(['bags', 'kg', 'MT', 'pcs', 'cft', 'm³', 'sq.ft'])->maxLength(20),
            Forms\Components\Toggle::make('is_active')->label('Tracked')->default(true)->inline(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->reorderable('sort')
            ->defaultSort('sort')
            ->columns([
                Tables\Columns\TextColumn::make('name'),
                Tables\Columns\TextColumn::make('unit'),
                Tables\Columns\IconColumn::make('is_active')->label('Tracked')->boolean(),
            ])
            ->actions([Tables\Actions\EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageKeyMaterials::route('/')];
    }
}
