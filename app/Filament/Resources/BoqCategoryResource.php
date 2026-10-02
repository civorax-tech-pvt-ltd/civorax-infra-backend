<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BoqCategoryResource\Pages;
use App\Filament\Resources\Concerns\SiteAccess;
use App\Models\BoqCategory;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Categories of the BOQ library. Team members can add new ones (also from the item form's "+");
 * renaming, reordering and deleting is for the admin panel.
 */
class BoqCategoryResource extends Resource
{
    use SiteAccess;

    protected static ?string $model = BoqCategory::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationGroup = 'Projects';

    protected static ?int $navigationSort = 7;

    protected static ?string $navigationLabel = 'BOQ Categories';

    protected static ?string $modelLabel = 'BOQ category';

    public static function canViewAny(): bool
    {
        return static::canUseSite();
    }

    public static function canCreate(): bool
    {
        return static::canUseSite();
    }

    public static function canEdit(Model $record): bool
    {
        return static::isAdminPanel();
    }

    public static function canDelete(Model $record): bool
    {
        return static::isAdminPanel() && $record->key !== 'other' && ! $record->items()->exists();
    }

    public static function canReorder(): bool
    {
        return static::isAdminPanel();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->required()
                ->maxLength(100)
                ->placeholder('e.g. Tile work')
                ->unique(BoqCategory::class, 'name', ignoreRecord: true)
                ->validationMessages(['unique' => 'This category already exists.']),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->reorderable('sort', fn (): bool => static::isAdminPanel())
            ->defaultSort('sort')
            ->paginated(false)
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->weight('bold'),
                Tables\Columns\TextColumn::make('items_count')
                    ->label('Library items')
                    ->counts('items'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('Rename'),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageBoqCategories::route('/'),
        ];
    }
}
