<?php

namespace App\Filament\Resources;

use App\Filament\Forms\BoqItemTagField;
use App\Filament\Resources\Concerns\SiteAccess;
use App\Filament\Resources\MusterRollResource\Pages;
use App\Models\Labourer;
use App\Models\MusterRoll;
use App\Models\MusterRollLine;
use App\Models\Project;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class MusterRollResource extends Resource
{
    use SiteAccess;

    protected static ?string $model = MusterRoll::class;

    protected static ?string $navigationIcon = 'heroicon-o-table-cells';

    protected static ?string $navigationGroup = 'Site';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Muster Rolls';

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
        return static::canUseSite() && ($record->isEditable() || static::canApproveSite() || static::canPayWages());
    }

    /**
     * Admins may delete any roll that is not approved (deleting a submitted one unlocks its attendance);
     * an approved roll must be returned first so wages and payments never disappear by accident.
     * Others may delete their own draft or returned rolls.
     */
    public static function canDelete(Model $record): bool
    {
        if (static::isAdminPanel()) {
            return $record->status !== 'approved';
        }

        return $record->isEditable() && $record->prepared_by === auth()->id();
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canApproveSite()) {
            return null;
        }

        $count = static::getEloquentQuery()->where('status', 'submitted')->count();

        return $count ? (string) $count : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Waiting for approval';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Site and month')
                    ->columns(4)
                    ->schema([
                        Forms\Components\Select::make('project_id')
                            ->label('Site / project')
                            ->relationship('project', 'title', fn (Builder $query) => static::siteProjectQuery($query))
                            ->rules([static::siteProjectRule()])
                            ->searchable()
                            ->preload()
                            ->required()
                            ->live()
                            ->disabledOn('edit')
                            ->columnSpan(2),
                        Forms\Components\ToggleButtons::make('calendar')
                            ->options(['bs' => 'B.S.', 'ad' => 'A.D.'])
                            ->default('bs')
                            ->inline()
                            ->required()
                            ->live()
                            ->disabledOn('edit')
                            ->afterStateUpdated(function (Set $set, string $state): void {
                                [$year, $month] = MusterRoll::monthOf($state, now(config('app.business_timezone')));
                                $set('year', $year);
                                $set('month', $month);
                            }),
                        Forms\Components\Grid::make(2)
                            ->columnSpan(1)
                            ->schema([
                                Forms\Components\Select::make('month')
                                    ->options(fn (Get $get): array => MusterRoll::monthOptions($get('calendar') ?? 'bs'))
                                    ->default(fn (): int => MusterRoll::monthOf('bs', now(config('app.business_timezone')))[1])
                                    ->required()
                                    ->disabledOn('edit')
                                    ->selectablePlaceholder(false),
                                Forms\Components\TextInput::make('year')
                                    ->numeric()
                                    ->integer()
                                    ->default(fn (): int => MusterRoll::monthOf('bs', now(config('app.business_timezone')))[0])
                                    ->required()
                                    ->disabledOn('edit')
                                    ->rules([
                                        fn (Get $get, ?MusterRoll $record): Closure => function (string $attribute, $value, Closure $fail) use ($get, $record): void {
                                            if ($record !== null) {
                                                return;
                                            }

                                            $calendar = $get('calendar') ?? 'bs';
                                            [$min, $max] = $calendar === 'bs' ? [2070, 2090] : [2013, 2033];

                                            if ((int) $value < $min || (int) $value > $max) {
                                                $fail("Enter a year between {$min} and {$max}.");

                                                return;
                                            }

                                            [$start, $end] = MusterRoll::periodFor($calendar, (int) $value, (int) $get('month'));

                                            if ($error = MusterRoll::overlapError($get('project_id'), $start, $end)) {
                                                $fail($error);
                                            }
                                        },
                                    ]),
                            ]),
                    ]),
                Forms\Components\Section::make('Cost per BOQ item')
                    ->visible(fn (Get $get): bool => (bool) Project::find($get('project_id'))?->track_item_costs)
                    ->schema([
                        BoqItemTagField::make('project_id', 'Gang worked on BOQ item (optional)'),
                    ]),
                Forms\Components\Section::make('Part II: reasons for unpaid wages')
                    ->description('Explain why a labourer\'s wage is still unpaid (e.g. absent on pay day, dispute). Printed on the roll.')
                    ->visibleOn('edit')
                    ->collapsible()
                    ->schema([
                        Forms\Components\Repeater::make('lines')
                            ->relationship('lines', fn (Builder $query) => $query->with('labourer'))
                            ->hiddenLabel()
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->columns(3)
                            // Item labels receive the owner roll as $record, so read the line from the item state.
                            ->itemLabel(fn (array $state): ?string => Labourer::withTrashed()->find($state['labourer_id'] ?? null)?->name)
                            ->schema([
                                Forms\Components\Placeholder::make('wage')
                                    ->label('Wage this month')
                                    ->content(fn ($record): string => $record instanceof MusterRollLine ? 'Rs '.number_format((float) $record->total_wage, 2).' · '.rtrim(rtrim(number_format((float) $record->present_days, 1), '0'), '.').' days' : '—'),
                                Forms\Components\TextInput::make('arrear_reason')
                                    ->label('Reason unpaid')
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('remarks')
                                    ->maxLength(255),
                            ]),
                    ]),
                Forms\Components\Section::make('Part III: work performed (measurement record)')
                    ->description('Work done by labourers this month, with the Measurement Book page for each quantity.')
                    ->visibleOn('edit')
                    ->disabled(fn (?MusterRoll $record): bool => $record?->isLocked() ?? false)
                    ->schema([
                        Forms\Components\Repeater::make('works')
                            ->relationship('works')
                            ->hiddenLabel()
                            ->orderColumn('sort')
                            ->addActionLabel('Add work item')
                            ->defaultItems(0)
                            ->columns(6)
                            ->schema([
                                Forms\Components\Select::make('labourer_id')
                                    ->label('Worker')
                                    // Inside the repeater $record is the work row, so take the roll from the page.
                                    ->options(fn ($livewire): array => ($roll = $livewire->getRecord()) instanceof MusterRoll
                                        ? $roll->lines()->with('labourer')->get()->mapWithKeys(fn (MusterRollLine $line): array => [$line->labourer_id => $line->labourer?->name])->all()
                                        : [])
                                    ->placeholder('All / gang'),
                                Forms\Components\TextInput::make('description')
                                    ->label('Work description')
                                    ->required()
                                    ->placeholder('e.g. Brick masonry 1:6, ground floor')
                                    ->columnSpan(2),
                                Forms\Components\TextInput::make('quantity')
                                    ->numeric(),
                                Forms\Components\TextInput::make('unit')
                                    ->datalist(['m³', 'm²', 'm', 'cft', 'sq.ft', 'rft', 'kg', 'nos', 'LS']),
                                Forms\Components\TextInput::make('mb_ref')
                                    ->label('MB Ref (Pg)'),
                                Forms\Components\TextInput::make('remarks')
                                    ->columnSpan(6),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('project')->withCount('lines')->withSum('lines', 'total_wage'))
            ->columns([
                Tables\Columns\TextColumn::make('project.title')
                    ->label('Site')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('month')
                    ->label('Month')
                    ->state(fn (MusterRoll $record): string => $record->label())
                    ->description(fn (MusterRoll $record): string => $record->starts_on->format('M j').' – '.$record->ends_on->format('M j, Y')),
                Tables\Columns\TextColumn::make('lines_count')
                    ->label('Labourers')
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('lines_sum_total_wage')
                    ->label('Total wages')
                    ->money('NPR'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => MusterRoll::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => static::statusColor($state))
                    ->tooltip(fn (MusterRoll $record): ?string => $record->review_note),
                Tables\Columns\TextColumn::make('preparer.name')
                    ->label('Prepared by')
                    ->toggleable(),
            ])
            ->defaultSort('starts_on', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(MusterRoll::STATUSES),
                Tables\Filters\SelectFilter::make('project_id')
                    ->label('Site')
                    ->relationship('project', 'title', fn (Builder $query) => static::siteProjectQuery($query)),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('print')
                    ->icon('heroicon-o-printer')
                    ->color('gray')
                    ->url(fn (MusterRoll $record): string => route('site.muster-rolls.print', $record), shouldOpenInNewTab: true),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make()
                        ->label(fn (MusterRoll $record): string => $record->isEditable() ? 'Edit Part II / III' : 'Edit reasons'),
                    static::deleteAction(Tables\Actions\DeleteAction::make()),
                ]),
            ]);
    }

    /**
     * Delete with a warning that says what happens to the month's attendance and payments.
     *
     * @template T of Tables\Actions\DeleteAction|\Filament\Actions\DeleteAction
     *
     * @param  T  $action
     * @return T
     */
    public static function deleteAction($action)
    {
        return $action
            ->visible(fn (MusterRoll $record): bool => static::canDelete($record))
            ->modalHeading(fn (MusterRoll $record): string => "Delete the {$record->label()} muster roll?")
            ->modalDescription(function (MusterRoll $record): string {
                $paid = (float) $record->wagePayments()->sum('amount');

                return 'Daily labour attendance is kept, and the month is unlocked so it can be corrected and a new roll prepared.'
                    .($paid > 0 ? ' Rs '.number_format($paid, 2).' already paid through this roll stays in the labourers\' ledgers.' : '');
            });
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->columns(4)
            ->schema([
                Infolists\Components\TextEntry::make('project.title')->label('Site'),
                Infolists\Components\TextEntry::make('period')
                    ->state(fn (MusterRoll $record): string => $record->label())
                    ->helperText(fn (MusterRoll $record): string => $record->starts_on->format('M j').' – '.$record->ends_on->format('M j, Y')),
                Infolists\Components\TextEntry::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => MusterRoll::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => static::statusColor($state)),
                Infolists\Components\TextEntry::make('total')
                    ->label('Total wages')
                    ->state(fn (MusterRoll $record): float => $record->totalWage())
                    ->money('NPR'),
                Infolists\Components\TextEntry::make('review_note')
                    ->label('Approver\'s note')
                    ->visible(fn (MusterRoll $record): bool => filled($record->review_note))
                    ->color(fn (MusterRoll $record): string => $record->status === 'returned' ? 'danger' : 'gray')
                    ->columnSpanFull(),
                Infolists\Components\Section::make()
                    ->schema([
                        Infolists\Components\ViewEntry::make('sheet')
                            ->hiddenLabel()
                            ->view('filament.infolists.muster-roll-sheet'),
                    ]),
            ]);
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'approved' => 'success',
            'submitted' => 'warning',
            'returned' => 'danger',
            default => 'gray',
        };
    }

    /**
     * Rows for the "Pay wages" form: each labourer on the roll who is still owed money on this site.
     *
     * @return list<array{labourer_id: int, name: string, due: float, amount: float}>
     */
    public static function payableRows(MusterRoll $roll, int|string|null $contractorId = null): array
    {
        return $roll->lines()
            ->with('labourer')
            ->get()
            ->filter(fn (MusterRollLine $line): bool => blank($contractorId) || (int) $line->labourer?->labour_contractor_id === (int) $contractorId)
            ->map(fn (MusterRollLine $line): array => [
                'labourer_id' => $line->labourer_id,
                'name' => $line->labourer?->name ?? '—',
                // Owed across all sites (one khata per labourer); the payment is booked to this roll's site.
                'due' => $due = max(0, Labourer::withTrashed()->find($line->labourer_id)->balance()),
                'amount' => $due,
            ])
            ->filter(fn (array $row): bool => $row['due'] > 0)
            ->sortBy('name')
            ->values()
            ->all();
    }

    public static function getEloquentQuery(): Builder
    {
        return static::scopeToSites(parent::getEloquentQuery());
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMusterRolls::route('/'),
            'create' => Pages\CreateMusterRoll::route('/create'),
            'view' => Pages\ViewMusterRoll::route('/{record}'),
            'edit' => Pages\EditMusterRoll::route('/{record}/edit'),
        ];
    }
}
