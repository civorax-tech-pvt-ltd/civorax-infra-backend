<?php

namespace App\Filament\Resources\VendorResource\Pages;

use App\Filament\Resources\VendorResource;
use App\Models\PurchaseBill;
use App\Models\Vendor;
use App\Models\VendorBalanceConfirmation;
use App\Models\VendorPayment;
use App\Models\WagePayment;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * A vendor's account: approved bills (total amount, rule 3), payments, running balance owed, and
 * balance confirmations received from the vendor.
 */
class VendorStatement extends Page
{
    use InteractsWithRecord;

    protected static string $resource = VendorResource::class;

    protected static string $view = 'filament.pages.vendor-statement';

    /**
     * @var array{from?: string|null, until?: string|null}
     */
    public array $filters = [];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(VendorResource::canSeeLedger(), 403);

        $this->form->fill();
    }

    public function getTitle(): string|Htmlable
    {
        return "Statement · {$this->getVendor()->name}";
    }

    public function getSubheading(): ?string
    {
        $vendor = $this->getVendor();

        return collect([$vendor->pan_vat_no ? "PAN/VAT {$vendor->pan_vat_no}" : null, $vendor->contact, $vendor->address])->filter()->implode(' · ');
    }

    public function getVendor(): Vendor
    {
        return $this->getRecord();
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('filters')
            ->columns(2)
            ->schema([
                Forms\Components\DatePicker::make('from')->live(),
                Forms\Components\DatePicker::make('until')->live(),
            ]);
    }

    /**
     * @return array{opening: float, rows: list<array<string, mixed>>, billed: float, paid: float, closing: float}
     */
    public function statement(): array
    {
        return $this->getVendor()->statement($this->filters['from'] ?? null, $this->filters['until'] ?? null);
    }

    protected function getHeaderActions(): array
    {
        $vendor = $this->getVendor();

        return [
            Actions\Action::make('pay')
                ->label('Record payment')
                ->icon('heroicon-o-banknotes')
                ->visible(fn (): bool => auth()->user()?->hasSitePower('pay_vendors'))
                ->modalDescription(fn (): string => 'Owed to '.$vendor->name.': Rs '.number_format($vendor->outstanding(), 2))
                ->form([
                    Forms\Components\Select::make('purchase_bill_id')
                        ->label('Against bill')
                        ->options(fn (): array => $vendor->purchaseBills()->approved()->with('project')->get()
                            ->filter(fn (PurchaseBill $bill): bool => $bill->amountOwed() > 0)
                            ->mapWithKeys(fn (PurchaseBill $bill): array => [$bill->id => "{$bill->bill_no} · {$bill->project?->title} · owed Rs ".number_format($bill->amountOwed(), 2)])
                            ->all())
                        ->placeholder('General payment (not against one bill)')
                        ->live(),
                    Forms\Components\Select::make('project_id')
                        ->label('Site (for cash paid out)')
                        ->options(fn (): array => $vendor->purchaseBills()->with('project')->get()->pluck('project.title', 'project_id')->filter()->all())
                        ->visible(fn (Get $get): bool => blank($get('purchase_bill_id'))),
                    Forms\Components\TextInput::make('amount')
                        ->numeric()
                        ->minValue(1)
                        ->prefix('Rs')
                        ->required()
                        ->maxValue(fn (Get $get): ?float => ($bill = PurchaseBill::find($get('purchase_bill_id'))) ? $bill->amountOwed() : null),
                    Forms\Components\DatePicker::make('paid_on')->default(now(config('app.business_timezone'))->toDateString())->required(),
                    Forms\Components\Select::make('method')->options(WagePayment::METHODS)->default('cash')->required(),
                    Forms\Components\TextInput::make('reference')->placeholder('Cheque / voucher / txn no.'),
                ])
                ->action(function (array $data) use ($vendor): void {
                    VendorPayment::create([...$data, 'vendor_id' => $vendor->id, 'paid_by' => auth()->id()]);
                    Notification::make()->title('Payment recorded')->success()->send();
                }),
            Actions\Action::make('confirm')
                ->label('Balance confirmation')
                ->icon('heroicon-o-document-check')
                ->color('gray')
                ->modalDescription('Record the vendor\'s signed confirmation of the balance (e.g. at fiscal year end).')
                ->fillForm(fn (): array => ['as_of' => now(config('app.business_timezone'))->toDateString(), 'balance' => $vendor->outstanding(), 'agreed' => true])
                ->form([
                    Forms\Components\DatePicker::make('as_of')->label('Balance as of')->required(),
                    Forms\Components\TextInput::make('balance')->label('Balance confirmed')->numeric()->prefix('Rs')->required(),
                    Forms\Components\Toggle::make('agreed')->label('Vendor agrees with our balance')->inline(false),
                    Forms\Components\TextInput::make('note'),
                    Forms\Components\FileUpload::make('document_path')->label('Signed confirmation (photo / PDF)')->disk('public')->directory('vendor-confirmations')->maxSize(8192),
                ])
                ->action(function (array $data) use ($vendor): void {
                    VendorBalanceConfirmation::create([...$data, 'vendor_id' => $vendor->id, 'recorded_by' => auth()->id()]);
                    Notification::make()->title('Confirmation recorded')->success()->send();
                }),
            Actions\Action::make('print')
                ->label('Print statement')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->url(fn (): string => route('site.vendors.statement', ['vendor' => $vendor, ...array_filter($this->filters)]), shouldOpenInNewTab: true),
        ];
    }
}
