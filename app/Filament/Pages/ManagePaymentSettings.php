<?php

namespace App\Filament\Pages;

use App\Models\PaymentSetting;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class ManagePaymentSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-qr-code';

    protected static ?string $navigationGroup = 'Academy';

    protected static ?int $navigationSort = 7;

    protected static ?string $navigationLabel = 'Payment Settings';

    protected static string $view = 'filament.pages.manage-payment-settings';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(PaymentSetting::current()->toArray());
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                FileUpload::make('qr_image_path')
                    ->label('Payment QR Code')
                    ->image()
                    ->disk('public')
                    ->directory('payment-qr')
                    ->required(),
                Textarea::make('instructions')
                    ->label('Payment Instructions')
                    ->rows(4)
                    ->placeholder('e.g. Scan the QR with eSewa/Khalti, then enter the transaction ID here after paying.'),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        PaymentSetting::current()->update($data);

        Notification::make()
            ->title('Payment settings saved')
            ->success()
            ->send();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }
}
