<?php

namespace App\Filament\Pages;

use App\Models\CompanySetting;
use App\Models\MusterRoll;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * VAT status and limits. Registering for VAT later is only a change here: old bills keep their treatment
 * because claimability is computed from each bill's date against the registration date.
 */
class ManageCompanySettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-building-library';

    protected static ?string $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Company & Tax Settings';

    protected static ?string $title = 'Company & Tax Settings';

    protected static string $view = 'filament.pages.manage-payment-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Filament::getCurrentPanel()?->getId() === 'admin' && (auth()->user()?->hasRole('super_admin') ?? false);
    }

    public function mount(): void
    {
        $this->form->fill(CompanySetting::current()->toArray());
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->schema([
                Section::make('VAT status')
                    ->description('While PAN-only, supplier VAT is part of cost and quotations carry no VAT line. Registering later only needs these two fields.')
                    ->columns(2)
                    ->schema([
                        Toggle::make('vat_registered')
                            ->label('Company is VAT registered')
                            ->live()
                            ->inline(false),
                        DatePicker::make('vat_registration_date')
                            ->label('VAT registration date')
                            ->required(fn (Get $get): bool => (bool) $get('vat_registered'))
                            ->visible(fn (Get $get): bool => (bool) $get('vat_registered'))
                            ->helperText('VAT on supplier bills dated on or after this day becomes claimable. Earlier bills keep VAT in cost.'),
                    ]),
                Section::make('Rates and limits')
                    ->description('Fill these from your accountant. They change with each Finance Act, so nothing is hard-coded.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('vat_rate')
                            ->label('VAT rate')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->suffix('%')
                            ->default(13)
                            ->required(),
                        TextInput::make('vat_registration_limit')
                            ->label('VAT registration turnover limit')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('Rs')
                            ->helperText('Annual turnover at which registration becomes compulsory.'),
                        TextInput::make('vat_warning_percent')
                            ->label('Warn when turnover reaches')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(100)
                            ->suffix('% of the limit')
                            ->default(80)
                            ->required(),
                        Select::make('fiscal_year_start_month')
                            ->label('Fiscal year starts in')
                            ->options(MusterRoll::BS_MONTHS)
                            ->default(4)
                            ->required()
                            ->selectablePlaceholder(false),
                    ]),
                Placeholder::make('note')
                    ->hiddenLabel()
                    ->content('The system only stores and calculates what these settings say. Confirm VAT and PAN rules with your accountant.'),
            ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();

        if (! ($data['vat_registered'] ?? false)) {
            $data['vat_registration_date'] = null;
        }

        CompanySetting::current()->update($data);

        Notification::make()->title('Company & tax settings saved')->success()->send();
    }
}
