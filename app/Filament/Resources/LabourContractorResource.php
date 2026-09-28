<?php

namespace App\Filament\Resources;

use App\Filament\Resources\Concerns\SiteAccess;
use App\Filament\Resources\LabourContractorResource\Pages;
use App\Models\LabourContractor;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class LabourContractorResource extends Resource
{
    use SiteAccess;

    protected static ?string $model = LabourContractor::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'Site';

    protected static ?int $navigationSort = 7;

    protected static ?string $navigationLabel = 'Naike / Contractors';

    protected static ?string $modelLabel = 'naike / labour contractor';

    protected static ?string $pluralModelLabel = 'naike / labour contractors';

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
        return static::canUseSite();
    }

    public static function canDelete(Model $record): bool
    {
        return static::isAdminPanel();
    }

    /**
     * @return array<Forms\Components\Component>
     */
    public static function contractorFields(): array
    {
        return [
            Forms\Components\TextInput::make('name')
                ->label('Naike / contractor name')
                ->required()
                ->maxLength(255),
            Forms\Components\TextInput::make('phone')
                ->tel()
                ->maxLength(20),
            Forms\Components\TextInput::make('address')
                ->maxLength(255),
            Forms\Components\TextInput::make('pan_no')
                ->label('PAN no.')
                ->maxLength(20),
            Forms\Components\Textarea::make('notes')
                ->columnSpanFull(),
            Forms\Components\Hidden::make('created_by')
                ->default(fn () => auth()->id()),
        ];
    }

    public static function form(Form $form): Form
    {
        return $form->schema(static::contractorFields());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('phone')
                    ->searchable(),
                Tables\Columns\TextColumn::make('labourers_count')
                    ->label('Labourers')
                    ->counts('labourers')
                    ->badge(),
                Tables\Columns\TextColumn::make('pan_no')
                    ->label('PAN')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('address')
                    ->toggleable(),
            ])
            ->defaultSort('name')
            ->actions([
                Tables\Actions\Action::make('account')
                    ->label('Account')
                    ->icon('heroicon-o-book-open')
                    ->color('gray')
                    ->url(fn (LabourContractor $record): string => static::getUrl('account', ['record' => $record]))
                    ->visible(fn (): bool => static::canSeeWages()),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageLabourContractors::route('/'),
            'account' => Pages\NaikeAccount::route('/{record}/account'),
        ];
    }
}
