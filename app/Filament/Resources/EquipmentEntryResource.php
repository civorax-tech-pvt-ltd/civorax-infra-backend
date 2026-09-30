<?php

namespace App\Filament\Resources;

use App\Filament\Forms\BoqItemTagField;
use App\Filament\Resources\Concerns\SiteAccess;
use App\Filament\Resources\EquipmentEntryResource\Pages;
use App\Models\EquipmentEntry;
use App\Models\Vendor;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Machine hire hours and transport trips recorded on site.
 */
class EquipmentEntryResource extends Resource
{
    use SiteAccess;

    protected static ?string $model = EquipmentEntry::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationGroup = 'Site';

    protected static ?int $navigationSort = 7;

    protected static ?string $navigationLabel = 'Equipment & Transport';

    protected static ?string $modelLabel = 'equipment / transport entry';

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

    public static function getNavigationBadge(): ?string
    {
        if (! auth()->user()?->hasSitePower('approve_equipment')) {
            return null;
        }

        $count = static::getEloquentQuery()->where('status', 'pending')->where('entered_by', '!=', auth()->id())->count();

        return $count ? (string) $count : null;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->columns(3)
            ->schema([
                Forms\Components\Select::make('project_id')
                    ->label('Site / project')
                    ->options(fn (): array => static::siteProjectQuery()->orderBy('title')->pluck('title', 'id')->all())
                    ->searchable()
                    ->required()
                    ->live(),
                Forms\Components\ToggleButtons::make('kind')
                    ->options(EquipmentEntry::KINDS)
                    ->default('equipment')
                    ->inline()
                    ->required(),
                Forms\Components\DatePicker::make('entry_date')
                    ->label('Date')
                    ->default(now(config('app.business_timezone'))->toDateString())
                    ->maxDate(now(config('app.business_timezone'))->toDateString())
                    ->required(),
                Forms\Components\TextInput::make('description')
                    ->placeholder('e.g. JCB excavation for footing / Tipper: sand from Chatara')
                    ->required()
                    ->maxLength(255)
                    ->columnSpan(2),
                BoqItemTagField::make(),
                Forms\Components\Select::make('vendor_id')
                    ->label('Hired from (optional)')
                    ->options(fn (): array => Vendor::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->placeholder('Own machine / paid in cash')
                    ->createOptionForm(PurchaseBillResource::vendorFields())
                    ->createOptionUsing(fn (array $data): int => Vendor::create([...$data, 'created_by' => auth()->id()])->getKey())
                    ->helperText('With a provider, this entry is their bill in the vendor ledger. Don\'t enter a separate bill for it.'),
                Forms\Components\TextInput::make('quantity')
                    ->numeric()
                    ->minValue(0.01)
                    ->required()
                    ->live(onBlur: true),
                Forms\Components\Select::make('unit')
                    ->options(EquipmentEntry::UNITS)
                    ->default('hour')
                    ->required()
                    ->selectablePlaceholder(false),
                Forms\Components\TextInput::make('rate')
                    ->label('Rate per unit')
                    ->numeric()
                    ->minValue(0)
                    ->prefix('Rs')
                    ->required()
                    ->live(onBlur: true)
                    ->helperText(fn (Get $get): string => 'Amount: Rs '.number_format((float) $get('quantity') * (float) $get('rate'), 2)),
                Forms\Components\FileUpload::make('photos')
                    ->label('Photo (log sheet / challan)')
                    ->image()
                    ->multiple()
                    ->maxFiles(4)
                    ->maxSize(8192)
                    ->imageResizeMode('contain')
                    ->imageResizeTargetWidth('1600')
                    ->imageResizeTargetHeight('1600')
                    ->disk('public')
                    ->directory('equipment')
                    ->columnSpan(2),
                Forms\Components\TextInput::make('note'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['project', 'vendor', 'enteredBy']))
            ->columns([
                Tables\Columns\TextColumn::make('entry_date')->label('Date')->date('M j, Y')->sortable(),
                Tables\Columns\TextColumn::make('kind')->badge()->formatStateUsing(fn (string $state): string => EquipmentEntry::KINDS[$state] ?? $state)->color('gray'),
                Tables\Columns\TextColumn::make('description')->wrap()->searchable()
                    ->description(fn (EquipmentEntry $record): ?string => $record->vendor?->name),
                Tables\Columns\TextColumn::make('quantity')
                    ->formatStateUsing(fn (EquipmentEntry $record): string => rtrim(rtrim(number_format((float) $record->quantity, 2), '0'), '.').' '.(EquipmentEntry::UNITS[$record->unit] ?? $record->unit).' × Rs '.number_format((float) $record->rate)),
                Tables\Columns\TextColumn::make('amount')->money('NPR')->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->money('NPR')),
                Tables\Columns\TextColumn::make('project.title')->label('Site')->toggleable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default => 'warning',
                    })
                    ->tooltip(fn (EquipmentEntry $record): ?string => $record->review_note),
            ])
            ->defaultSort('entry_date', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('kind')->options(EquipmentEntry::KINDS),
                Tables\Filters\SelectFilter::make('status')->options(['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected']),
                Tables\Filters\SelectFilter::make('project_id')
                    ->label('Site')
                    ->options(fn (): array => static::siteProjectQuery()->orderBy('title')->pluck('title', 'id')->all()),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (EquipmentEntry $record): bool => $record->status === 'pending' && EquipmentEntry::canBeReviewedBy(auth()->user(), $record))
                    ->requiresConfirmation()
                    ->action(function (EquipmentEntry $record): void {
                        $record->approve(auth()->user());
                        Notification::make()->title('Approved and added to project cost')->success()->send();
                    }),
                Tables\Actions\Action::make('reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (EquipmentEntry $record): bool => $record->status === 'pending' && EquipmentEntry::canBeReviewedBy(auth()->user(), $record))
                    ->form([Forms\Components\TextInput::make('note')->label('Reason')->required()])
                    ->action(fn (EquipmentEntry $record, array $data) => $record->reject(auth()->user(), $data['note'])),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make()
                        ->modalContent(fn (EquipmentEntry $record) => view('filament.costs.bill-photos', ['urls' => $record->photoUrls()])),
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
            'index' => Pages\ManageEquipmentEntries::route('/'),
        ];
    }
}
