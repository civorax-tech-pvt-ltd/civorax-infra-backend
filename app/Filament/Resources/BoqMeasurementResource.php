<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BoqMeasurementResource\Pages;
use App\Filament\Resources\Concerns\SiteAccess;
use App\Models\BoqItem;
use App\Models\BoqMeasurement;
use App\Models\MusterRoll;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Work measured on site against BOQ items. Engineers enter; roles given "approve_boq_measurements" approve
 * (never their own entry, except super admins). Only approved measurements count towards progress.
 */
class BoqMeasurementResource extends Resource
{
    use SiteAccess;

    protected static ?string $model = BoqMeasurement::class;

    protected static ?string $navigationIcon = 'heroicon-o-calculator';

    protected static ?string $navigationGroup = 'Site';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'BOQ Measurements';

    protected static ?string $modelLabel = 'measurement';

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
        return static::canEdit($record);
    }

    public static function canReview(): bool
    {
        return (bool) auth()->user()?->hasSitePower('approve_boq_measurements');
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canReview()) {
            return null;
        }

        $count = static::getEloquentQuery()->where('status', 'pending')->where('entered_by', '!=', auth()->id())->count();

        return $count ? (string) $count : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Waiting for your approval';
    }

    public static function form(Form $form): Form
    {
        $today = now(config('app.business_timezone'))->toDateString();

        return $form
            ->columns(2)
            ->schema([
                Forms\Components\Select::make('project_id')
                    ->label('Site / project')
                    ->options(fn (): array => static::siteProjectQuery()->has('boqItems')->orderBy('title')->pluck('title', 'id')->all())
                    ->searchable()
                    ->required()
                    ->live()
                    ->dehydrated(false)
                    ->afterStateUpdated(fn (Set $set) => $set('boq_item_id', null))
                    ->afterStateHydrated(fn (Set $set, ?BoqMeasurement $record) => $set('project_id', $record?->boqItem?->project_id))
                    ->disabledOn('edit'),
                Forms\Components\Select::make('boq_item_id')
                    ->label('BOQ item')
                    ->options(fn (Get $get): array => BoqItem::query()
                        ->where('project_id', $get('project_id'))
                        ->orderBy('sort')
                        ->get()
                        ->mapWithKeys(fn (BoqItem $item): array => [$item->id => trim(($item->code ? "{$item->code} · " : '').$item->description)." ({$item->quantity} {$item->unit})"])
                        ->all())
                    ->searchable()
                    ->required()
                    ->live()
                    ->disabledOn('edit')
                    ->helperText(fn (Get $get): ?string => ($item = BoqItem::with('latestApprovedMeasurement')->find($get('boq_item_id')))
                        ? 'Approved so far: '.$item->executedQuantity()." of {$item->quantity} {$item->unit}. Enter the new total to date."
                        : null),
                Forms\Components\DatePicker::make('measured_date')
                    ->label('Measured on')
                    ->default($today)
                    ->maxDate($today)
                    ->required(),
                Forms\Components\TextInput::make('executed_quantity')
                    ->label('Total done to date (cumulative)')
                    ->numeric()
                    ->minValue(0)
                    ->required(),
                Forms\Components\FileUpload::make('photos')
                    ->label('Site photos')
                    ->image()
                    ->multiple()
                    ->maxFiles(8)
                    ->maxSize(8192)
                    ->imageResizeMode('contain')
                    ->imageResizeTargetWidth('1600')
                    ->imageResizeTargetHeight('1600')
                    ->disk('public')
                    ->directory('boq-measurements')
                    ->columnSpanFull(),
                Forms\Components\Textarea::make('remarks')
                    ->rows(2)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['boqItem.project', 'boqItem.latestApprovedMeasurement', 'enteredBy', 'approver']))
            ->columns([
                Tables\Columns\TextColumn::make('measured_date')
                    ->label('Date')
                    ->date('M j, Y')
                    ->description(fn (BoqMeasurement $record): string => 'B.S. '.MusterRoll::bsDate($record->measured_date))
                    ->sortable(),
                Tables\Columns\TextColumn::make('boqItem.project.title')
                    ->label('Site')
                    ->searchable(),
                Tables\Columns\TextColumn::make('boqItem.description')
                    ->label('BOQ item')
                    ->wrap()
                    ->limit(60)
                    ->searchable(),
                Tables\Columns\TextColumn::make('executed_quantity')
                    ->label('Done to date')
                    ->formatStateUsing(fn (BoqMeasurement $record): string => rtrim(rtrim(number_format((float) $record->executed_quantity, 3), '0'), '.')
                        .' / '.rtrim(rtrim(number_format((float) $record->boqItem->quantity, 3), '0'), '.').' '.$record->boqItem->unit)
                    ->description(fn (BoqMeasurement $record): ?string => (float) $record->executed_quantity > (float) $record->boqItem->quantity ? 'Excess, variation needed' : null),
                Tables\Columns\TextColumn::make('photos')
                    ->label('Photos')
                    ->state(fn (BoqMeasurement $record): int => count($record->photos ?? []))
                    ->icon('heroicon-o-photo'),
                Tables\Columns\TextColumn::make('enteredBy.name')
                    ->label('By'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => BoqMeasurement::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default => 'warning',
                    })
                    ->tooltip(fn (BoqMeasurement $record): ?string => $record->review_note),
            ])
            ->defaultSort('measured_date', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(BoqMeasurement::STATUSES)
                    ->default(fn (): ?string => static::canReview() ? 'pending' : null),
                Tables\Filters\SelectFilter::make('project')
                    ->label('Site')
                    ->options(fn (): array => static::siteProjectQuery()->has('boqItems')->orderBy('title')->pluck('title', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when($data['value'] ?? null, fn (Builder $query, $projectId) => $query->whereHas('boqItem', fn (Builder $query) => $query->where('project_id', $projectId)))),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (BoqMeasurement $record): bool => $record->status === 'pending' && BoqMeasurement::canBeReviewedBy(auth()->user(), $record))
                    ->form([Forms\Components\TextInput::make('note')->label('Note (optional)')])
                    ->action(function (BoqMeasurement $record, array $data): void {
                        $record->approve(auth()->user(), $data['note'] ?? null);
                        Notification::make()->title('Approved. Progress updated.')->success()->send();
                    }),
                Tables\Actions\Action::make('reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (BoqMeasurement $record): bool => $record->status === 'pending' && BoqMeasurement::canBeReviewedBy(auth()->user(), $record))
                    ->form([Forms\Components\TextInput::make('note')->label('Reason')->required()])
                    ->action(function (BoqMeasurement $record, array $data): void {
                        $record->reject(auth()->user(), $data['note']);
                        Notification::make()->title('Rejected')->warning()->send();
                    }),
                Tables\Actions\ViewAction::make()
                    ->modalContent(fn (BoqMeasurement $record) => view('filament.boq.measurement-history', ['item' => $record->boqItem->load('measurements.enteredBy', 'measurements.approver')]))
                    ->modalHeading(fn (BoqMeasurement $record): string => "Measurements · {$record->boqItem->description}"),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereHas('boqItem', fn (Builder $query) => static::scopeToSites($query));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageBoqMeasurements::route('/'),
        ];
    }
}
