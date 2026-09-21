<?php

namespace App\Filament\Student\Resources\EnrollmentResource\Pages;

use App\Filament\Student\Resources\EnrollmentResource;
use App\Models\CoursePaymentSubmission;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewEnrollment extends ViewRecord
{
    protected static string $resource = EnrollmentResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                TextEntry::make('course.title')->label('Course'),
                TextEntry::make('course.type')->label('Mode'),
                TextEntry::make('course.duration')->label('Duration'),
                TextEntry::make('course.description')->columnSpanFull(),
                TextEntry::make('enrolled_at')->date(),
                TextEntry::make('course.fee')->label('Course Fee')->money('NPR'),
                TextEntry::make('paid')
                    ->label('Amount Paid')
                    ->state(fn ($record) => $record->coursePayments->sum('amount'))
                    ->money('NPR'),
                TextEntry::make('balance')
                    ->label('Balance Due')
                    ->state(fn ($record) => $record->course->fee - $record->coursePayments->sum('amount'))
                    ->money('NPR')
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'success'),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('makePayment')
                ->label('Make Payment')
                ->icon('heroicon-o-qr-code')
                ->color('primary')
                ->modalHeading('Make a Payment')
                ->modalContent(fn () => view('filament.modals.payment-qr'))
                ->form([
                    TextInput::make('amount')
                        ->label('Amount Paid')
                        ->numeric()
                        ->required()
                        ->prefix('NPR')
                        ->default(fn () => $this->record->course->fee - $this->record->coursePayments->sum('amount')),
                    TextInput::make('transaction_reference')
                        ->label('Transaction ID / Reference')
                        ->required()
                        ->maxLength(255),
                    FileUpload::make('screenshot_path')
                        ->label('Payment Screenshot (optional)')
                        ->image()
                        ->disk('public')
                        ->directory('payment-screenshots'),
                ])
                ->action(function (array $data) {
                    CoursePaymentSubmission::create([
                        'enrollment_id' => $this->record->id,
                        'amount' => $data['amount'],
                        'transaction_reference' => $data['transaction_reference'],
                        'screenshot_path' => $data['screenshot_path'] ?? null,
                    ]);

                    Notification::make()
                        ->title('Payment submitted for verification')
                        ->body('Our team will review and confirm your payment shortly.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
