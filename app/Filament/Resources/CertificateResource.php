<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CertificateResource\Pages;
use App\Models\Certificate;
use App\Models\CertificateSetting;
use App\Models\Enrollment;
use Closure;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class CertificateResource extends Resource
{
    protected static ?string $model = Certificate::class;

    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $navigationGroup = 'Academy';

    protected static ?int $navigationSort = 5;

    /**
     * Certificates are issued by admins only.
     */
    public static function canAccess(): bool
    {
        return Filament::getCurrentPanel()?->getId() === 'admin';
    }

    public static function canViewAny(): bool
    {
        return static::canAccess();
    }

    public static function canCreate(): bool
    {
        return static::canAccess();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canAccess();
    }

    public static function canDelete(Model $record): bool
    {
        return false; // revoke instead, so a printed number never points at nothing
    }

    public static function form(Form $form): Form
    {
        $today = now(config('app.business_timezone'))->toDateString();

        return $form
            ->columns(2)
            ->schema([
                Forms\Components\Select::make('enrollment_id')
                    ->label('Student & course')
                    ->options(fn (?Certificate $record): array => Enrollment::query()
                        ->with(['student', 'course'])
                        ->where(fn (Builder $query) => $query->doesntHave('certificate')->when($record, fn (Builder $query) => $query->orWhereKey($record->enrollment_id)))
                        ->latest('enrolled_at')
                        ->get()
                        ->mapWithKeys(fn (Enrollment $enrollment): array => [$enrollment->id => "{$enrollment->student?->fullname} — {$enrollment->course?->title} (enrolled {$enrollment->enrolled_at?->format('M j, Y')})"])
                        ->all())
                    ->searchable()
                    ->required()
                    ->disabledOn('edit')
                    ->live()
                    ->afterStateUpdated(function (Set $set, $state): void {
                        $enrollment = Enrollment::with(['student', 'course'])->find($state);
                        $set('student_name', $enrollment?->student?->fullname);
                        $set('photo_path', $enrollment?->student?->photo_path ? [(string) Str::uuid() => $enrollment->student->photo_path] : []);
                        $set('course_title', $enrollment?->course?->title);
                    })
                    ->rules([
                        fn (?Certificate $record): Closure => function (string $attribute, $value, Closure $fail) use ($record): void {
                            if ($record === null && Certificate::query()->where('enrollment_id', $value)->exists()) {
                                $fail('This enrollment already has a certificate. Edit or revoke that one.');
                            }
                        },
                    ])
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('student_name')
                    ->label('Name on certificate')
                    ->required()
                    ->maxLength(255)
                    ->helperText('Check the spelling: this is printed exactly as typed.'),
                Forms\Components\TextInput::make('course_title')
                    ->label('Course as printed')
                    ->required()
                    ->maxLength(255)
                    ->helperText('You can add the batch, e.g. "AutoCAD 2D — morning batch".'),
                Forms\Components\TextInput::make('grade')
                    ->label('Result / grade')
                    ->datalist(['A+', 'A', 'B+', 'B', 'C+', 'C', 'Distinction', 'First Division', 'Pass'])
                    ->maxLength(50),
                Forms\Components\TextInput::make('instructor_name')
                    ->label('Instructor')
                    ->default(fn (): ?string => CertificateSetting::current()->default_instructor)
                    ->maxLength(255),
                Forms\Components\DatePicker::make('completed_on')
                    ->label('Completion date')
                    ->default($today)
                    ->required()
                    ->helperText(fn (?string $state): ?string => $state ? Certificate::dualDate(Carbon::parse($state)) : null),
                Forms\Components\DatePicker::make('issued_on')
                    ->label('Issue date')
                    ->default($today)
                    ->required()
                    ->disabledOn('edit')
                    ->helperText('The year decides the certificate number series.'),
                Forms\Components\FileUpload::make('photo_path')
                    ->label('Student photo (verification page only)')
                    ->helperText('Taken from the student record. Shown when someone scans the QR to verify, never printed on the certificate.')
                    ->image()
                    ->avatar()
                    ->imageEditor()
                    ->imageCropAspectRatio('1:1')
                    ->imageResizeTargetWidth('600')
                    ->imageResizeTargetHeight('600')
                    ->disk('public')
                    ->directory('students')
                    ->maxSize(4096)
                    ->columnSpanFull(),
                Forms\Components\Textarea::make('remarks')
                    ->label('Internal remarks')
                    ->helperText('Not printed.')
                    ->columnSpanFull(),
                Forms\Components\Hidden::make('issued_by')
                    ->default(fn () => auth()->id()),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('number')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->copyable(),
                Tables\Columns\TextColumn::make('student_name')
                    ->label('Student')
                    ->searchable(),
                Tables\Columns\TextColumn::make('course_title')
                    ->label('Course')
                    ->searchable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('grade')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('completed_on')
                    ->label('Completed')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->state(fn (Certificate $record): string => $record->isRevoked() ? 'Revoked' : 'Valid')
                    ->color(fn (string $state): string => $state === 'Valid' ? 'success' : 'danger')
                    ->tooltip(fn (Certificate $record): ?string => $record->revoke_reason),
            ])
            ->defaultSort('number', 'desc')
            ->filters([
                Tables\Filters\TernaryFilter::make('revoked_at')
                    ->label('Status')
                    ->nullable()
                    ->trueLabel('Revoked')
                    ->falseLabel('Valid'),
            ])
            ->actions([
                Tables\Actions\Action::make('print')
                    ->label('Print / PDF')
                    ->icon('heroicon-o-printer')
                    ->url(fn (Certificate $record): string => route('certificates.show', $record), shouldOpenInNewTab: true),
                Tables\Actions\Action::make('verify')
                    ->icon('heroicon-o-qr-code')
                    ->color('gray')
                    ->url(fn (Certificate $record): string => $record->verifyUrl(), shouldOpenInNewTab: true),
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('revoke')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->visible(fn (Certificate $record): bool => ! $record->isRevoked())
                    ->form([
                        Forms\Components\TextInput::make('reason')
                            ->required()
                            ->placeholder('e.g. Issued by mistake, name misspelt (re-issued)'),
                    ])
                    ->modalDescription('The verification page will show it as revoked and the student can no longer download it.')
                    ->action(function (Certificate $record, array $data): void {
                        $record->revoke($data['reason']);
                        Notification::make()->title("{$record->number} revoked")->warning()->send();
                    }),
                Tables\Actions\Action::make('restore')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->visible(fn (Certificate $record): bool => $record->isRevoked())
                    ->requiresConfirmation()
                    ->action(fn (Certificate $record) => $record->restore()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCertificates::route('/'),
            'create' => Pages\CreateCertificate::route('/create'),
            'edit' => Pages\EditCertificate::route('/{record}/edit'),
        ];
    }
}
