<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BoqMasterItemResource\Pages;
use App\Filament\Resources\Concerns\SiteAccess;
use App\Filament\Resources\ProjectResource\RelationManagers\BoqItemsRelationManager;
use App\Models\BoqCategory;
use App\Models\BoqMasterItem;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * The master BOQ library ("estimate module"). Anyone on site can add items; admins edit, merge and deactivate.
 */
class BoqMasterItemResource extends Resource
{
    use SiteAccess;

    protected static ?string $model = BoqMasterItem::class;

    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationGroup = 'Projects';

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'BOQ Library';

    protected static ?string $modelLabel = 'BOQ item';

    protected static ?string $pluralModelLabel = 'BOQ library';

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
        return static::isAdminPanel() && ! $record->isUsed();
    }

    /**
     * Fields shared with "New item" on a project's BOQ tab (which saves to the library too).
     *
     * @return array<Forms\Components\Component>
     */
    public static function itemFields(string $rateField = 'default_rate'): array
    {
        return [
            Forms\Components\TextInput::make('code')
                ->maxLength(50)
                ->live(onBlur: true)
                ->placeholder('e.g. RCC-01')
                ->unique(BoqMasterItem::class, 'code', ignoreRecord: true),
            Forms\Components\Select::make('category')
                ->options(fn (): array => BoqMasterItem::categoryOptions())
                ->searchable()
                ->preload()
                // Search the saved list on every keystroke, so categories added a moment ago are found too.
                ->getSearchResultsUsing(fn (string $search): array => BoqCategory::query()
                    ->where('name', 'like', "%{$search}%")
                    ->orderBy('sort')
                    ->limit(50)
                    ->pluck('name', 'key')
                    ->all())
                ->getOptionLabelUsing(fn (?string $value): ?string => BoqMasterItem::categoryLabel($value))
                ->helperText('Not in the list? Use the + button to add a category (it is saved straight away).')
                ->createOptionForm([
                    Forms\Components\TextInput::make('name')
                        ->label('New category')
                        ->placeholder('e.g. Tile work')
                        ->required()
                        ->maxLength(100),
                ])
                ->createOptionModalHeading('Add a BOQ category')
                // Reuses an existing category typed with different capitals instead of making a duplicate.
                ->createOptionUsing(fn (array $data): string => BoqCategory::findOrCreateByName($data['name'])->key),
            Forms\Components\Textarea::make('description')
                ->required()
                ->rows(3)
                ->autosize()
                ->maxLength(2000)
                ->live(onBlur: true)
                ->placeholder('e.g. RCC 1:1.5:3 (M20) in slab including shuttering and curing')
                ->columnSpanFull(),
            Forms\Components\Placeholder::make('duplicates')
                ->hiddenLabel()
                ->columnSpanFull()
                ->visible(fn (Get $get, $record): bool => static::similar($get, $record)->isNotEmpty())
                ->content(fn (Get $get, $record): HtmlString => new HtmlString(
                    '<div style="padding:.6rem .8rem;border-radius:.6rem;background:rgb(254 243 199);color:rgb(146 64 14);font-size:.85rem">'
                    .'<b>Possible duplicate.</b> Similar items already in the library: '
                    .static::similar($get, $record)->map(fn (BoqMasterItem $item): string => e($item->label()).($item->is_active ? '' : ' (inactive)'))->implode('; ')
                    .'. Use the existing one if it is the same work.</div>'
                )),
            Forms\Components\TextInput::make('unit')
                ->required()
                ->datalist(BoqMasterItem::UNITS)
                ->maxLength(20),
            ($rateField === 'rate' ? BoqItemsRelationManager::rateField() : Forms\Components\TextInput::make($rateField)->numeric()->minValue(0)->prefix('Rs')->required())
                ->label($rateField === 'default_rate' ? 'Default rate' : 'Rate')
                ->helperText($rateField === 'default_rate'
                    ? 'Usual rate, pre-filled when the item is added to a project.'
                    : 'Used for this project and saved as the library rate for next time.'),
            Forms\Components\Toggle::make('rate_includes_vat')
                ->label('Rate includes supplier VAT')
                ->helperText('While the company is PAN-only, rates for materials bought on VAT bills include the supplier\'s VAT.')
                ->inline(false),
        ];
    }

    /**
     * @return Collection<int, BoqMasterItem>
     */
    protected static function similar(Get $get, mixed $record): Collection
    {
        return BoqMasterItem::similarTo($get('code'), $get('description'), $record instanceof BoqMasterItem ? $record->getKey() : null);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->columns(2)
            ->schema([
                ...static::itemFields(),
                BoqItemsRelationManager::normsField()
                    ->helperText('Optional. Copied to projects that use this item, for estimating material cost per item.')
                    ->columnSpanFull(),
                Forms\Components\Toggle::make('is_active')
                    ->label('Active (shown when building BOQs)')
                    ->default(true)
                    ->inline(false),
                Forms\Components\Placeholder::make('rate_updated_at')
                    ->label('Default rate last changed')
                    ->content(fn (?BoqMasterItem $record): string => $record?->rate_updated_at?->diffForHumans() ?? '—')
                    ->helperText('Projects keep their own copied rate; changing it here only affects new projects.')
                    ->visibleOn('edit'),
                Forms\Components\Hidden::make('created_by')
                    ->default(fn () => auth()->id()),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount(['boqItems' => fn (Builder $q) => $q->whereHas('project')]))
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('description')
                    ->searchable()
                    ->wrap()
                    ->limit(90),
                Tables\Columns\TextColumn::make('category')
                    ->formatStateUsing(fn (?string $state): ?string => BoqMasterItem::categoryLabel($state))
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('unit'),
                Tables\Columns\TextColumn::make('default_rate')
                    ->label('Rate')
                    ->money('NPR')
                    ->sortable()
                    ->description(fn (BoqMasterItem $record): ?string => $record->rate_includes_vat ? 'incl. VAT' : null),
                Tables\Columns\TextColumn::make('boq_items_count')
                    ->label('Used in')
                    ->suffix(' BOQ lines')
                    ->tooltip(fn (BoqMasterItem $record): ?string => $record->boq_items_count > 0
                        ? 'In use on a project, so it can\'t be deleted. Deactivate it to hide it from new BOQs, or remove it from those projects first.'
                        : null)
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
            ])
            // Most-used first, so common items are quick to find.
            ->defaultSort(fn (Builder $query) => $query->orderByDesc('boq_items_count')->orderByDesc('updated_at'))
            ->filters([
                Tables\Filters\SelectFilter::make('category')
                    ->options(fn (): array => BoqMasterItem::categoryOptions()),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active')
                    ->default(true),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('toggleActive')
                        ->label(fn (BoqMasterItem $record): string => $record->is_active ? 'Deactivate' : 'Activate')
                        ->icon(fn (BoqMasterItem $record): string => $record->is_active ? 'heroicon-o-eye-slash' : 'heroicon-o-eye')
                        ->visible(fn (): bool => static::isAdminPanel())
                        ->action(fn (BoqMasterItem $record) => $record->update(['is_active' => ! $record->is_active])),
                    Tables\Actions\Action::make('merge')
                        ->label('Merge into…')
                        ->icon('heroicon-o-arrows-pointing-in')
                        ->visible(fn (): bool => static::isAdminPanel())
                        ->modalDescription('Use this for a duplicate: its project BOQ lines move to the chosen item and this one is deactivated.')
                        ->form([
                            Forms\Components\Select::make('target_id')
                                ->label('Keep this item')
                                ->options(fn (BoqMasterItem $record): array => BoqMasterItem::query()->active()->whereKeyNot($record->getKey())->orderBy('description')->get()
                                    ->mapWithKeys(fn (BoqMasterItem $item): array => [$item->id => $item->label()])->all())
                                ->searchable()
                                ->required(),
                        ])
                        ->action(function (BoqMasterItem $record, array $data): void {
                            BoqMasterItem::findOrFail($data['target_id'])->absorb($record);
                            Notification::make()->title('Merged')->success()->send();
                        }),
                    Tables\Actions\DeleteAction::make()
                        ->modalDescription('Only items never used in a project can be deleted. Used items can be deactivated instead.'),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageBoqMasterItems::route('/'),
        ];
    }
}
