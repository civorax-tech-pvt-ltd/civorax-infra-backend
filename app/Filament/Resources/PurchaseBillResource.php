<?php

namespace App\Filament\Resources;

use App\Filament\Forms\BoqItemTagField;
use App\Filament\Resources\Concerns\SiteAccess;
use App\Filament\Resources\PurchaseBillResource\Pages;
use App\Models\CompanySetting;
use App\Models\KeyMaterial;
use App\Models\ProjectCost;
use App\Models\PurchaseBill;
use App\Models\Vendor;
use App\Models\VendorPayment;
use App\Models\WagePayment;
use App\Models\WorkOrder;
use Closure;
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
 * Supplier bills for a project. Site staff enter them with a photo; approvers (Approval Settings) approve;
 * approval posts the cost to the project ledger by the VAT rules.
 */
class PurchaseBillResource extends Resource
{
    use SiteAccess;

    protected static ?string $model = PurchaseBill::class;

    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $navigationGroup = 'Site';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Purchase Bills';

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
        return $record->status !== 'approved' && ((int) $record->entered_by === (int) auth()->id() || static::isAdminPanel()) && ! $record->payments()->exists();
    }

    public static function canReview(): bool
    {
        return (bool) auth()->user()?->hasSitePower('approve_purchase_bills');
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
        return 'Waiting for approval';
    }

    /**
     * @return array<Forms\Components\Component>
     */
    public static function vendorFields(): array
    {
        return [
            Forms\Components\TextInput::make('name')->required()->maxLength(255),
            Forms\Components\Select::make('vendor_type')
                ->options(['supplier' => 'Supplier', 'contractor' => 'Contractor', 'subcontractor' => 'Subcontractor'])
                ->default('supplier')
                ->required(),
            Forms\Components\TextInput::make('contact')->required()->maxLength(255),
            Forms\Components\TextInput::make('pan_vat_no')->label('PAN / VAT no.')->maxLength(20),
            Forms\Components\TextInput::make('address')->maxLength(255),
        ];
    }

    public static function form(Form $form): Form
    {
        $vatRate = (float) CompanySetting::current()->vat_rate;

        return $form
            ->columns(3)
            ->schema([
                Forms\Components\Section::make('Bill')
                    ->columns(3)
                    ->schema([
                        Forms\Components\Select::make('project_id')
                            ->label('Site / project')
                            ->options(fn (): array => static::siteProjectQuery()->orderBy('title')->pluck('title', 'id')->all())
                            ->searchable()
                            ->required()
                            ->live(),
                        Forms\Components\Select::make('vendor_id')
                            ->label('Vendor / supplier')
                            ->options(fn (): array => Vendor::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Set $set, $state) => $set('vendor_pan_vat', Vendor::find($state)?->pan_vat_no))
                            ->createOptionForm(static::vendorFields())
                            ->createOptionUsing(fn (array $data): int => Vendor::create([...$data, 'created_by' => auth()->id()])->getKey()),
                        Forms\Components\Select::make('work_order_id')
                            ->label('Against work order')
                            ->placeholder('None (supplier bill)')
                            ->options(fn (Get $get): array => WorkOrder::query()->open()->with('vendor')
                                ->when($get('project_id'), fn (Builder $query, $projectId) => $query->where('project_id', $projectId))
                                ->whereIn('project_id', static::siteProjectQuery()->select('projects.id'))
                                ->get()
                                ->mapWithKeys(fn (WorkOrder $order): array => [$order->id => $order->label().' · left Rs '.number_format($order->remaining(), 2)])
                                ->all())
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(function (Set $set, $state): void {
                                if ($order = WorkOrder::find($state)) {
                                    $set('project_id', $order->project_id);
                                    $set('vendor_id', $order->vendor_id);
                                    $set('category', 'subcontract');
                                }
                            })
                            ->helperText('For a subcontractor\'s running bill.'),
                        Forms\Components\TextInput::make('vendor_pan_vat')
                            ->label('Vendor PAN / VAT no.')
                            ->maxLength(20),
                        Forms\Components\ToggleButtons::make('bill_type')
                            ->options(PurchaseBill::TYPES)
                            ->default('pan')
                            ->inline()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Get $get, Set $set, string $state) use ($vatRate): void {
                                $set('vat_amount', $state === 'vat' && filled($get('base_amount')) ? round((float) $get('base_amount') * $vatRate / 100, 2) : 0);
                            }),
                        Forms\Components\TextInput::make('bill_no')
                            ->label('Bill no.')
                            ->required()
                            ->maxLength(50)
                            ->rules([
                                fn (Get $get, ?PurchaseBill $record): Closure => function (string $attribute, $value, Closure $fail) use ($get, $record): void {
                                    if ($duplicate = PurchaseBill::duplicateOf($get('vendor_id'), $value, $record?->getKey())) {
                                        $fail("This vendor's bill {$value} is already entered ({$duplicate->bill_date->format('M j, Y')}, ".(PurchaseBill::STATUSES[$duplicate->status] ?? $duplicate->status).').');
                                    }
                                },
                            ]),
                        Forms\Components\DatePicker::make('bill_date')
                            ->default(now(config('app.business_timezone'))->toDateString())
                            ->maxDate(now(config('app.business_timezone'))->toDateString())
                            ->required(),
                        BoqItemTagField::make(),
                        Forms\Components\Select::make('category')
                            ->label('Cost category')
                            ->options(collect(ProjectCost::CATEGORIES)->except('labour')->all())
                            ->default('materials')
                            ->required()
                            ->selectablePlaceholder(false),
                        Forms\Components\Toggle::make('billed_to_company')
                            ->label('Bill is made out to the company')
                            ->default(true)
                            ->inline(false)
                            ->helperText('Turn off if the bill is in someone else\'s name. It will be flagged and kept out of project cost until a super admin reviews it.')
                            ->columnSpan(2),
                    ]),
                Forms\Components\Section::make('Amount')
                    ->columns(3)
                    ->schema([
                        Forms\Components\TextInput::make('base_amount')
                            ->label(fn (Get $get): string => $get('bill_type') === 'vat' ? 'Taxable amount (before VAT)' : 'Bill amount')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('Rs')
                            ->required()
                            ->live(onBlur: true)
                            ->rules([
                                fn (Get $get, ?PurchaseBill $record): Closure => function (string $attribute, $value, Closure $fail) use ($get, $record): void {
                                    $order = WorkOrder::find($get('work_order_id'));
                                    $total = (float) $value + ($get('bill_type') === 'vat' ? (float) $get('vat_amount') : 0);

                                    if ($order && $total > $order->remaining($record) + 0.005) {
                                        $fail('The bill total (Rs '.number_format($total, 2).") is more than what is left on {$order->number} (Rs ".number_format($order->remaining($record), 2).'). Record extra work as a variation first.');
                                    }
                                },
                            ])
                            ->afterStateUpdated(function (Get $get, Set $set, $state) use ($vatRate): void {
                                if ($get('bill_type') === 'vat') {
                                    $set('vat_amount', round((float) $state * $vatRate / 100, 2));
                                }
                            }),
                        Forms\Components\TextInput::make('vat_amount')
                            ->label('VAT amount')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('Rs')
                            ->live(onBlur: true)
                            ->visible(fn (Get $get): bool => $get('bill_type') === 'vat')
                            ->helperText("Filled at {$vatRate}%. Correct it to match the bill if needed."),
                        Forms\Components\Placeholder::make('total')
                            ->label('Bill total')
                            ->content(fn (Get $get): string => 'Rs '.number_format((float) $get('base_amount') + ($get('bill_type') === 'vat' ? (float) $get('vat_amount') : 0), 2)),
                    ]),
                Forms\Components\Section::make('Items (optional)')
                    ->description('Quantities help track key materials like cement, rod, bricks, sand and aggregate.')
                    ->collapsible()
                    ->collapsed(fn (?PurchaseBill $record): bool => blank($record?->items))
                    ->schema([
                        Forms\Components\Repeater::make('items')
                            ->hiddenLabel()
                            ->defaultItems(0)
                            ->addActionLabel('Add item')
                            ->columns(6)
                            ->schema([
                                Forms\Components\Select::make('key_material_id')
                                    ->label('Key material')
                                    ->options(fn (): array => KeyMaterial::options())
                                    ->placeholder('Other item')
                                    ->live()
                                    ->afterStateUpdated(function (Set $set, $state): void {
                                        if ($material = KeyMaterial::find($state)) {
                                            $set('item', $material->name);
                                            $set('unit', $material->unit);
                                        }
                                    })
                                    ->columnSpan(2),
                                Forms\Components\TextInput::make('item')->required()->placeholder('e.g. OPC cement')->columnSpan(2),
                                Forms\Components\TextInput::make('quantity')->numeric()->required(),
                                Forms\Components\TextInput::make('unit')->datalist(['bags', 'kg', 'MT', 'pcs', 'cft', 'trip', 'm³', 'nos'])->required(),
                                Forms\Components\TextInput::make('rate')->label('Rate (before VAT)')->numeric()->prefix('Rs')->columnSpan(2),
                            ]),
                    ]),
                Forms\Components\FileUpload::make('photos')
                    ->label('Photo of the bill')
                    ->image()
                    ->multiple()
                    ->maxFiles(4)
                    ->maxSize(8192)
                    ->imageResizeMode('contain')
                    ->imageResizeTargetWidth('1800')
                    ->imageResizeTargetHeight('1800')
                    ->disk('public')
                    ->directory('purchase-bills')
                    ->required()
                    ->columnSpan(2),
                Forms\Components\Textarea::make('description')
                    ->label('Note')
                    ->rows(3),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['project', 'vendor', 'enteredBy'])->withSum('payments', 'amount'))
            ->columns([
                Tables\Columns\TextColumn::make('bill_date')
                    ->label('Date')
                    ->date('M j, Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('bill_no')
                    ->label('Bill no.')
                    ->searchable()
                    ->description(fn (PurchaseBill $record): string => PurchaseBill::TYPES[$record->bill_type]),
                Tables\Columns\TextColumn::make('vendor.name')
                    ->label('Vendor')
                    ->searchable(),
                Tables\Columns\TextColumn::make('project.title')
                    ->label('Site')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('total_amount')
                    ->label('Bill total')
                    ->money('NPR')
                    ->sortable()
                    ->description(fn (PurchaseBill $record): string => 'Cost to project: Rs '.number_format($record->ledgerCost(), 2).($record->vatNotClaimable() > 0 ? ' (incl. VAT)' : '')),
                Tables\Columns\TextColumn::make('paid_status')
                    ->label('Paid')
                    ->state(fn (PurchaseBill $record): string => match (true) {
                        (float) $record->payments_sum_amount <= 0 => 'Unpaid',
                        (float) $record->payments_sum_amount + 0.005 < (float) $record->total_amount => 'Part paid',
                        default => 'Paid',
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Paid' => 'success',
                        'Part paid' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state, PurchaseBill $record): string => (PurchaseBill::STATUSES[$state] ?? $state).($record->isFlagged() && $state === 'pending' ? ' · flagged' : ''))
                    ->color(fn (string $state, PurchaseBill $record): string => match (true) {
                        $state === 'approved' => 'success',
                        $state === 'rejected' => 'danger',
                        $record->isFlagged() => 'danger',
                        default => 'warning',
                    })
                    ->tooltip(fn (PurchaseBill $record): ?string => $record->isFlagged() ? 'Not made out to the company: needs super admin review.' : $record->review_note),
            ])
            ->defaultSort('bill_date', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(PurchaseBill::STATUSES),
                Tables\Filters\SelectFilter::make('project_id')
                    ->label('Site')
                    ->options(fn (): array => static::siteProjectQuery()->orderBy('title')->pluck('title', 'id')->all()),
                Tables\Filters\SelectFilter::make('vendor_id')
                    ->label('Vendor')
                    ->options(fn (): array => Vendor::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                Tables\Filters\TernaryFilter::make('billed_to_company')
                    ->label('Made out to the company'),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (PurchaseBill $record): bool => $record->status === 'pending' && PurchaseBill::canBeReviewedBy(auth()->user(), $record))
                    ->modalDescription(fn (PurchaseBill $record): string => $record->isFlagged()
                        ? 'This bill is NOT made out to the company. Approve only after checking why; its cost will then enter the project.'
                        : 'Rs '.number_format($record->ledgerCost(), 2).' will be added to the project cost ('.($record->vat_claimable ? 'VAT claimable, cost excludes VAT' : 'cost includes the full bill').').')
                    ->form([Forms\Components\TextInput::make('note')->label('Note (optional)')])
                    ->action(function (PurchaseBill $record, array $data): void {
                        $record->approve(auth()->user(), $data['note'] ?? null);
                        Notification::make()->title('Bill approved and added to project cost')->success()->send();
                    }),
                Tables\Actions\Action::make('reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (PurchaseBill $record): bool => $record->status === 'pending' && PurchaseBill::canBeReviewedBy(auth()->user(), $record))
                    ->form([Forms\Components\TextInput::make('note')->label('Reason')->required()])
                    ->action(function (PurchaseBill $record, array $data): void {
                        $record->reject(auth()->user(), $data['note']);
                        Notification::make()->title('Bill rejected')->warning()->send();
                    }),
                static::payAction(),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make()
                        ->modalContent(fn (PurchaseBill $record) => view('filament.costs.bill-photos', ['urls' => $record->photoUrls(), 'bill' => $record])),
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\Action::make('reopen')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->visible(fn (PurchaseBill $record): bool => $record->status !== 'pending' && static::isAdminPanel())
                        ->requiresConfirmation()
                        ->modalDescription('Back to pending so it can be corrected; its cost leaves the project until approved again.')
                        ->action(fn (PurchaseBill $record) => $record->reopen()),
                    Tables\Actions\DeleteAction::make(),
                ]),
            ]);
    }

    public static function payAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('pay')
            ->label('Pay')
            ->icon('heroicon-o-banknotes')
            ->color('gray')
            ->visible(fn (PurchaseBill $record): bool => $record->status === 'approved' && (float) $record->payments_sum_amount + 0.005 < (float) $record->total_amount && auth()->user()?->hasSitePower('pay_vendors'))
            ->fillForm(fn (PurchaseBill $record): array => ['amount' => $record->amountOwed(), 'paid_on' => now(config('app.business_timezone'))->toDateString(), 'method' => 'cash'])
            ->form(fn (PurchaseBill $record): array => [
                Forms\Components\TextInput::make('amount')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue($record->amountOwed())
                    ->prefix('Rs')
                    ->required()
                    ->helperText('Still owed on this bill: Rs '.number_format($record->amountOwed(), 2)),
                Forms\Components\DatePicker::make('paid_on')->required(),
                Forms\Components\Select::make('method')->options(WagePayment::METHODS)->required(),
                Forms\Components\TextInput::make('reference')->placeholder('Cheque / voucher / txn no.'),
            ])
            ->action(function (PurchaseBill $record, array $data): void {
                VendorPayment::create([...$data, 'purchase_bill_id' => $record->id, 'paid_by' => auth()->id()]);
                Notification::make()->title('Payment recorded')->success()->send();
            });
    }

    public static function getEloquentQuery(): Builder
    {
        return static::scopeToSites(parent::getEloquentQuery());
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPurchaseBills::route('/'),
            'create' => Pages\CreatePurchaseBill::route('/create'),
            'edit' => Pages\EditPurchaseBill::route('/{record}/edit'),
        ];
    }
}
