<?php

namespace App\Filament\Pages;

use App\Models\CertificateSetting;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Letterhead, numbering and signatures printed on completion certificates.
 */
class ManageCertificateSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-document-check';

    protected static ?string $navigationGroup = 'Academy';

    protected static ?int $navigationSort = 8;

    protected static ?string $navigationLabel = 'Certificate Settings';

    protected static ?string $title = 'Certificate Settings';

    protected static string $view = 'filament.pages.manage-payment-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Filament::getCurrentPanel()?->getId() === 'admin' && (auth()->user()?->hasRole('super_admin') ?? false);
    }

    public function mount(): void
    {
        $this->form->fill(CertificateSetting::current()->toArray());
    }

    public function form(Form $form): Form
    {
        $image = fn (string $name, string $label, string $help): FileUpload => FileUpload::make($name)
            ->label($label)
            ->helperText($help)
            ->image()
            ->disk('public')
            ->directory('certificates')
            ->maxSize(2048);

        return $form
            ->statePath('data')
            ->schema([
                Section::make('Letterhead')
                    ->description('Printed at the top of every certificate.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('organization_name')
                            ->placeholder(config('app.name'))
                            ->maxLength(255),
                        TextInput::make('address')
                            ->placeholder('Belbari, Koshi Province, Nepal')
                            ->maxLength(255),
                        TextInput::make('phone')->maxLength(50),
                        TextInput::make('pan_number')->label('PAN number')->maxLength(50),
                        $image('logo_path', 'Logo (optional)', 'Shown above the name. A transparent PNG looks best.'),
                        Toggle::make('show_name_with_logo')
                            ->label('Print organization name under the logo')
                            ->helperText('Turn off if your logo already includes the company name. The name is still used on the verification page.')
                            ->default(true)
                            ->inline(false),
                        TextInput::make('number_prefix')
                            ->label('Certificate number prefix')
                            ->default('CERT')
                            ->required()
                            ->alphaDash()
                            ->maxLength(10)
                            ->helperText('Just the letters, e.g. CERT. The year is added automatically: CERT-2026-0001, restarting every year.'),
                    ]),
                Section::make('Signatures')
                    ->columns(2)
                    ->schema([
                        TextInput::make('default_instructor')
                            ->label('Default instructor name')
                            ->helperText('Pre-filled when issuing; can be changed per certificate.')
                            ->maxLength(255),
                        $image('instructor_signature_path', 'Instructor signature', 'Scanned signature on a white or transparent background.'),
                        TextInput::make('director_name')
                            ->label('Director name')
                            ->maxLength(255),
                        TextInput::make('director_title')
                            ->label('Director title')
                            ->default('Director')
                            ->maxLength(100),
                        $image('director_signature_path', 'Director signature', 'Leave empty to sign by hand after printing.'),
                        $image('stamp_path', 'Company stamp', 'Scan of your round stamp (chhap) on white, or a transparent PNG. Printed over the director\'s signature. Leave empty to stamp by hand.'),
                    ]),
            ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();

        foreach (['logo_path', 'instructor_signature_path', 'director_signature_path', 'stamp_path'] as $image) {
            if (! str_ends_with((string) ($data[$image] ?? ''), '-trimmed.png')) {
                $data[$image] = CertificateSetting::trimImage($data[$image] ?? null);
            }
        }

        $data['number_prefix'] = (new CertificateSetting(['number_prefix' => $data['number_prefix']]))->numberPrefix();

        CertificateSetting::current()->update($data);
        $this->form->fill(CertificateSetting::current()->toArray());

        Notification::make()->title('Certificate settings saved')->success()->send();
    }
}
