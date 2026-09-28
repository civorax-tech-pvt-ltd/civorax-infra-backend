<?php

namespace App\Filament\Resources;

use App\Filament\Resources\Concerns\SiteAccess;
use App\Filament\Resources\WagePaymentResource\Pages;
use App\Models\Labourer;
use App\Models\WagePayment;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class WagePaymentResource extends Resource
{
    use SiteAccess;

    protected static ?string $model = WagePayment::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Site';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Wages & Advances';

    protected static ?string $modelLabel = 'wage payment';

    public static function canViewAny(): bool
    {
        return static::canPayWages();
    }

    public static function canCreate(): bool
    {
        return static::canPayWages();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canPayWages();
    }

    public static function canDelete(Model $record): bool
    {
        return static::isAdminPanel();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->columns(2)
            ->schema(static::paymentFields());
    }

    /**
     * "Owed: Rs 7,750" / "Advance outstanding: Rs 2,000" for a labourer's whole account.
     */
    public static function balanceText(?Labourer $labourer): ?string
    {
        if ($labourer === null) {
            return null;
        }

        $balance = $labourer->balance();

        return match (true) {
            $balance > 0 => 'Owed to '.$labourer->name.' (all sites): Rs '.number_format($balance, 2),
            $balance < 0 => 'Advance outstanding with '.$labourer->name.': Rs '.number_format(-$balance, 2),
            default => $labourer->name.' has a clear account.',
        };
    }

    /**
     * Payment form fields; the ledger page passes its labourer so the picker is replaced by a fixed value.
     *
     * @return array<Forms\Components\Component>
     */
    public static function paymentFields(?Labourer $labourer = null): array
    {
        return [
            Forms\Components\ToggleButtons::make('type')
                ->options(WagePayment::TYPES)
                ->default('advance')
                ->inline()
                ->required()
                ->live()
                ->helperText(fn (Get $get): string => match ($get('type')) {
                    'recovery' => 'The labourer pays back advance money in cash. It reduces their outstanding advance.',
                    'wage' => 'Wages paid against approved muster rolls.',
                    default => 'Deducted automatically from the labourer\'s next approved wages, at any site.',
                })
                ->columnSpanFull(),
            Forms\Components\Select::make('project_id')
                ->label('Site / project')
                ->helperText('The site this money is booked to (for project labour cost).')
                ->relationship('project', 'title', fn (Builder $query) => static::siteProjectQuery($query))
                ->rules([static::siteProjectRule()])
                ->searchable()
                ->preload()
                ->required(),
            $labourer
                ? Forms\Components\Hidden::make('labourer_id')->default($labourer->id)
                : Forms\Components\Select::make('labourer_id')
                    ->label('Labourer')
                    ->relationship('labourer', 'name', fn (Builder $query) => $query->active())
                    ->getOptionLabelFromRecordUsing(fn (Labourer $record): string => $record->selectLabel())
                    ->searchable(['name', 'father_name', 'phone'])
                    ->preload()
                    ->required()
                    ->live()
                    ->helperText(fn (Get $get): ?string => static::balanceText(Labourer::withTrashed()->find($get('labourer_id')))),
            Forms\Components\TextInput::make('amount')
                ->numeric()
                ->minValue(1)
                ->prefix('Rs')
                ->required()
                // Returned advances are stored negative; always show and type a positive amount.
                ->formatStateUsing(fn ($state): ?float => $state === null ? null : abs((float) $state)),
            Forms\Components\DatePicker::make('paid_on')
                ->default(now(config('app.business_timezone'))->toDateString())
                ->required(),
            Forms\Components\Select::make('method')
                ->options(WagePayment::METHODS)
                ->default('cash')
                ->required(),
            Forms\Components\TextInput::make('reference')
                ->placeholder('Voucher / txn no.'),
            Forms\Components\Select::make('labour_contractor_id')
                ->label('Handed to naike')
                ->relationship('contractor', 'name')
                ->placeholder('Paid to the labourer'),
            Forms\Components\TextInput::make('note')
                ->columnSpanFull(),
            Forms\Components\Hidden::make('paid_by')
                ->default(fn () => auth()->id()),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['project', 'labourer', 'contractor', 'musterRoll']))
            ->columns([
                Tables\Columns\TextColumn::make('paid_on')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('labourer.name')
                    ->searchable()
                    ->description(fn (WagePayment $record): ?string => $record->contractor ? "via {$record->contractor->name}" : null),
                Tables\Columns\TextColumn::make('project.title')
                    ->label('Site')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => WagePayment::TYPES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'advance' => 'warning',
                        'recovery' => 'info',
                        default => 'success',
                    }),
                Tables\Columns\TextColumn::make('amount')
                    ->money('NPR')
                    ->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->money('NPR')),
                Tables\Columns\TextColumn::make('method')
                    ->formatStateUsing(fn (string $state): string => WagePayment::METHODS[$state] ?? $state)
                    ->description(fn (WagePayment $record): ?string => $record->reference),
                Tables\Columns\TextColumn::make('musterRoll.month')
                    ->label('Muster roll')
                    ->state(fn (WagePayment $record): ?string => $record->musterRoll?->label())
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->defaultSort('paid_on', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('project_id')
                    ->label('Site')
                    ->relationship('project', 'title', fn (Builder $query) => static::siteProjectQuery($query)),
                Tables\Filters\SelectFilter::make('type')
                    ->options(WagePayment::TYPES),
                Tables\Filters\SelectFilter::make('labourer_id')
                    ->label('Labourer')
                    ->relationship('labourer', 'name')
                    ->searchable(),
                Tables\Filters\SelectFilter::make('labour_contractor_id')
                    ->label('Naike')
                    ->relationship('contractor', 'name'),
            ])
            ->actions([
                Tables\Actions\Action::make('ledger')
                    ->icon('heroicon-o-book-open')
                    ->color('gray')
                    ->url(fn (WagePayment $record): string => LabourerResource::getUrl('ledger', ['record' => $record->labourer_id])),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return static::scopeToSites(parent::getEloquentQuery());
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageWagePayments::route('/'),
        ];
    }
}
