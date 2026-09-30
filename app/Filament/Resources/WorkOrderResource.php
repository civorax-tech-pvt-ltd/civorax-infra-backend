<?php

namespace App\Filament\Resources;

use App\Filament\Forms\BoqItemTagField;
use App\Filament\Resources\Concerns\SiteAccess;
use App\Filament\Resources\WorkOrderResource\Pages;
use App\Models\Vendor;
use App\Models\WorkOrder;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Subcontractor work orders: the agreed amount is "committed" cost until billed.
 */
class WorkOrderResource extends Resource
{
    use SiteAccess;

    protected static ?string $model = WorkOrder::class;

    protected static ?string $navigationIcon = 'heroicon-o-briefcase';

    protected static ?string $navigationGroup = 'Site';

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'Work Orders';

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
        return $record->status === 'pending' && ((int) $record->entered_by === (int) auth()->id() || static::isAdminPanel());
    }

    public static function canDelete(Model $record): bool
    {
        return static::canEdit($record) && ! $record->bills()->exists();
    }

    public static function getNavigationBadge(): ?string
    {
        if (! auth()->user()?->hasSitePower('approve_work_orders')) {
            return null;
        }

        $count = static::getEloquentQuery()->where('status', 'pending')->where('entered_by', '!=', auth()->id())->count();

        return $count ? (string) $count : null;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->columns(2)
            ->schema([
                Forms\Components\Select::make('project_id')
                    ->label('Site / project')
                    ->options(fn (): array => static::siteProjectQuery()->orderBy('title')->pluck('title', 'id')->all())
                    ->searchable()
                    ->required()
                    ->live(),
                Forms\Components\Select::make('vendor_id')
                    ->label('Subcontractor')
                    ->options(fn (): array => Vendor::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->required()
                    ->createOptionForm(PurchaseBillResource::vendorFields())
                    ->createOptionUsing(fn (array $data): int => Vendor::create([...$data, 'created_by' => auth()->id()])->getKey()),
                Forms\Components\TextInput::make('scope')
                    ->label('Work')
                    ->placeholder('e.g. Plumbing and sanitary fitting, ground + first floor')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('agreed_amount')
                    ->label('Agreed amount (incl. VAT if any)')
                    ->numeric()
                    ->minValue(1)
                    ->prefix('Rs')
                    ->required(),
                Forms\Components\Grid::make(2)->schema([
                    Forms\Components\DatePicker::make('start_date'),
                    Forms\Components\DatePicker::make('end_date')->afterOrEqual('start_date'),
                ])->columnSpan(1),
                BoqItemTagField::make('project_id', 'BOQ item (optional; its bills inherit it)'),
                Forms\Components\Textarea::make('terms')
                    ->label('Terms (payment stages, retention, materials by whom…)')
                    ->rows(3)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['project', 'vendor'])->withSum(['bills as billed' => fn (Builder $query) => $query->where('status', 'approved')], 'total_amount'))
            ->columns([
                Tables\Columns\TextColumn::make('number')->searchable()->weight('bold'),
                Tables\Columns\TextColumn::make('vendor.name')->label('Subcontractor')->searchable(),
                Tables\Columns\TextColumn::make('scope')->label('Work')->wrap()->limit(60)->searchable(),
                Tables\Columns\TextColumn::make('project.title')->label('Site')->toggleable(),
                Tables\Columns\TextColumn::make('agreed_amount')->label('Agreed')->money('NPR')->sortable(),
                Tables\Columns\TextColumn::make('billed')
                    ->label('Billed')
                    ->money('NPR')
                    ->placeholder('Rs 0.00')
                    ->description(fn (WorkOrder $record): ?string => $record->status === 'approved' ? 'Committed: Rs '.number_format(max(0, (float) $record->agreed_amount - (float) $record->billed), 2) : null),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => WorkOrder::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'approved' => 'success',
                        'pending' => 'warning',
                        'rejected', 'cancelled' => 'danger',
                        default => 'gray',
                    })
                    ->tooltip(fn (WorkOrder $record): ?string => $record->review_note),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(WorkOrder::STATUSES),
                Tables\Filters\SelectFilter::make('project_id')
                    ->label('Site')
                    ->options(fn (): array => static::siteProjectQuery()->orderBy('title')->pluck('title', 'id')->all()),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (WorkOrder $record): bool => $record->status === 'pending' && WorkOrder::canBeReviewedBy(auth()->user(), $record))
                    ->form([Forms\Components\TextInput::make('note')->label('Note (optional)')])
                    ->action(function (WorkOrder $record, array $data): void {
                        $record->approve(auth()->user(), $data['note'] ?? null);
                        Notification::make()->title('Approved. The agreed amount is now committed cost.')->success()->send();
                    }),
                Tables\Actions\Action::make('reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (WorkOrder $record): bool => $record->status === 'pending' && WorkOrder::canBeReviewedBy(auth()->user(), $record))
                    ->form([Forms\Components\TextInput::make('note')->label('Reason')->required()])
                    ->action(function (WorkOrder $record, array $data): void {
                        $record->reject(auth()->user(), $data['note']);
                        Notification::make()->title('Work order rejected')->warning()->send();
                    }),
                Tables\Actions\Action::make('bill')
                    ->label('Enter bill')
                    ->icon('heroicon-o-receipt-percent')
                    ->color('gray')
                    ->visible(fn (WorkOrder $record): bool => $record->status === 'approved')
                    ->url(fn (WorkOrder $record): string => PurchaseBillResource::getUrl('create', ['work_order' => $record->id])),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('close')
                        ->label('Close (work finished)')
                        ->icon('heroicon-o-lock-closed')
                        ->visible(fn (WorkOrder $record): bool => $record->status === 'approved' && WorkOrder::canBeReviewedBy(auth()->user(), $record))
                        ->requiresConfirmation()
                        ->modalDescription(fn (WorkOrder $record): string => 'Anything not billed (Rs '.number_format(max(0, $record->remaining()), 2).') stops counting as committed cost.')
                        ->action(fn (WorkOrder $record) => $record->close()),
                    Tables\Actions\Action::make('cancel')
                        ->label('Cancel')
                        ->icon('heroicon-o-no-symbol')
                        ->color('danger')
                        ->visible(fn (WorkOrder $record): bool => $record->status === 'approved' && WorkOrder::canBeReviewedBy(auth()->user(), $record))
                        ->form([Forms\Components\TextInput::make('note')->label('Reason')->required()])
                        ->action(fn (WorkOrder $record, array $data) => $record->close('cancelled', $data['note'])),
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make(),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return static::scopeToSites(parent::getEloquentQuery());
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageWorkOrders::route('/'),
        ];
    }
}
