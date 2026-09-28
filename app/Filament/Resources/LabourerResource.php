<?php

namespace App\Filament\Resources;

use App\Filament\Resources\Concerns\SiteAccess;
use App\Filament\Resources\LabourerResource\Pages;
use App\Models\Labourer;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class LabourerResource extends Resource
{
    use SiteAccess;

    protected static ?string $model = Labourer::class;

    protected static ?string $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationGroup = 'Site';

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'Labourers';

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
    public static function labourerFields(): array
    {
        return [
            Forms\Components\TextInput::make('name')
                ->label('Name of labourer')
                ->required()
                ->maxLength(255),
            Forms\Components\TextInput::make('father_name')
                ->label("Father's name")
                ->maxLength(255),
            Forms\Components\Select::make('work_type')
                ->options(Labourer::WORK_TYPES)
                ->required()
                ->default('helper'),
            Forms\Components\TextInput::make('daily_wage')
                ->label('Wage rate (per day)')
                ->numeric()
                ->minValue(1)
                ->prefix('Rs')
                ->required()
                ->helperText('Changing it later only affects days marked after the change.'),
            Forms\Components\Select::make('labour_contractor_id')
                ->label('Came through (naike)')
                ->relationship('contractor', 'name')
                ->searchable()
                ->preload()
                ->createOptionForm(LabourContractorResource::contractorFields())
                ->placeholder('Direct / none'),
            Forms\Components\TextInput::make('phone')
                ->tel()
                ->maxLength(20),
            Forms\Components\TextInput::make('citizenship_no')
                ->label('Citizenship no.')
                ->maxLength(50),
            Forms\Components\TextInput::make('address')
                ->maxLength(255),
            Forms\Components\FileUpload::make('photo_path')
                ->label('Photo')
                ->image()
                ->disk('public')
                ->directory('labourers')
                ->imageResizeMode('cover')
                ->imageResizeTargetWidth('400')
                ->maxSize(4096),
            Forms\Components\Toggle::make('is_active')
                ->label('Currently working with us')
                ->default(true),
            Forms\Components\Hidden::make('created_by')
                ->default(fn () => auth()->id()),
        ];
    }

    public static function form(Form $form): Form
    {
        return $form->schema(static::labourerFields());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('contractor'))
            ->columns([
                Tables\Columns\ImageColumn::make('photo_path')
                    ->label('')
                    ->disk('public')
                    ->circular(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable(['name', 'father_name', 'phone', 'citizenship_no'])
                    ->sortable()
                    ->description(fn (Labourer $record): ?string => $record->father_name ? "s/o {$record->father_name}" : null),
                Tables\Columns\TextColumn::make('work_type')
                    ->formatStateUsing(fn (string $state): string => Labourer::WORK_TYPES[$state] ?? $state)
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('daily_wage')
                    ->label('Rate / day')
                    ->money('NPR')
                    ->sortable(),
                Tables\Columns\TextColumn::make('contractor.name')
                    ->label('Naike')
                    ->placeholder('Direct'),
                Tables\Columns\TextColumn::make('balance')
                    ->label('Wages due')
                    ->state(fn (Labourer $record): float => max(0, static::balanceOf($record)))
                    ->money('NPR')
                    ->color(fn ($state): ?string => $state > 0 ? 'danger' : 'gray')
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderByRaw(Labourer::balanceSql().' '.($direction === 'desc' ? 'desc' : 'asc')))
                    ->visible(fn (): bool => static::canSeeWages()),
                Tables\Columns\TextColumn::make('advance')
                    ->label('Advance held')
                    ->state(fn (Labourer $record): float => max(0, -static::balanceOf($record)))
                    ->money('NPR')
                    ->color(fn ($state): ?string => $state > 0 ? 'warning' : 'gray')
                    ->visible(fn (): bool => static::canSeeWages()),
                Tables\Columns\TextColumn::make('phone')
                    ->toggleable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
            ])
            ->defaultSort('name')
            ->filters([
                Tables\Filters\SelectFilter::make('work_type')
                    ->options(Labourer::WORK_TYPES),
                Tables\Filters\SelectFilter::make('labour_contractor_id')
                    ->label('Naike')
                    ->relationship('contractor', 'name'),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active')
                    ->default(true),
                Tables\Filters\SelectFilter::make('account')
                    ->label('Account')
                    ->options(['due' => 'Has unpaid wages', 'advance' => 'Holds an advance'])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'due' => $query->whereRaw(Labourer::balanceSql().' > 0'),
                        'advance' => $query->whereRaw(Labourer::balanceSql().' < 0'),
                        default => $query,
                    })
                    ->visible(fn (): bool => static::canSeeWages()),
                Tables\Filters\TrashedFilter::make()
                    ->visible(fn (): bool => static::isAdminPanel()),
            ])
            ->actions([
                Tables\Actions\Action::make('ledger')
                    ->icon('heroicon-o-book-open')
                    ->color('gray')
                    ->url(fn (Labourer $record): string => static::getUrl('ledger', ['record' => $record]))
                    ->visible(fn (): bool => static::canSeeWages()),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
                Tables\Actions\RestoreAction::make(),
            ]);
    }

    /**
     * The list query selects the balance; records loaded any other way calculate it.
     */
    protected static function balanceOf(Labourer $record): float
    {
        return (float) ($record->getAttribute('account_balance') ?? $record->balance());
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class])->withBalance();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageLabourers::route('/'),
            'ledger' => Pages\LabourerLedger::route('/{record}/ledger'),
        ];
    }
}
