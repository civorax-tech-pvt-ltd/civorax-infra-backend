<?php

namespace App\Filament\Client\Resources\ProjectResource\Pages;

use App\Filament\Client\Resources\ProjectResource;
use App\Models\Payment;
use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\ProjectPaymentSubmission;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewProject extends ViewRecord
{
    protected static string $resource = ProjectResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                TextEntry::make('title'),
                TextEntry::make('projectType.name')->label('Type'),
                TextEntry::make('description')
                    ->label('Scope of work')
                    ->state(fn (Project $record): string => $record->descriptionHtml())
                    ->html()
                    ->prose()
                    ->columnSpanFull(),
                TextEntry::make('site_address'),
                TextEntry::make('city'),
                TextEntry::make('ward_no')->label('Ward No.'),
                TextEntry::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Project::STATUSES[$state] ?? $state),
                ViewEntry::make('progress')
                    ->label('Progress')
                    ->view('filament.components.progress-bar'),
                TextEntry::make('estimated_end_date')->date()->placeholder('—'),
                Section::make('Payments')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('fee')
                            ->label('Contract fee')
                            ->money('NPR')
                            ->placeholder('To be quoted')
                            ->helperText(fn (Project $record): ?string => ($extra = $record->contractValue() - (float) $record->fee) > 0
                                ? '+ approved extra work NPR '.number_format($extra, 2).' = NPR '.number_format($record->contractValue(), 2)
                                : null),
                        TextEntry::make('extra_work')
                            ->label('Approved extra work')
                            ->state(fn (Project $record): array => $record->variations()->approved()->get()
                                ->map(fn ($variation): string => "{$variation->title}: NPR ".number_format((float) $variation->amount, 2))->all())
                            ->listWithLineBreaks()
                            ->visible(fn (Project $record): bool => $record->variations()->approved()->exists())
                            ->columnSpanFull(),
                        TextEntry::make('paid')
                            ->label('Paid')
                            ->state(fn (Project $record): float => $record->amountPaid())
                            ->money('NPR'),
                        TextEntry::make('due_now')
                            ->label('Due now')
                            ->state(fn (Project $record): ?float => $record->amountDueNow())
                            ->money('NPR')
                            ->placeholder('—')
                            ->color(fn (?float $state): ?string => $state > 0 ? 'danger' : null)
                            ->helperText('For completed milestones'),
                        TextEntry::make('balance')
                            ->label('Total balance')
                            ->state(fn (Project $record): ?float => $record->balanceDue())
                            ->money('NPR')
                            ->placeholder('—'),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    /**
     * @return array<int, string>
     */
    protected function billableMilestoneOptions(): array
    {
        return $this->record->milestones()
            ->where('billing_percent', '>', 0)
            ->orderBy('sequence')
            ->get()
            ->mapWithKeys(fn (ProjectMilestone $milestone): array => [
                $milestone->id => $milestone->title.' — NPR '.number_format((float) $milestone->amountLeft(), 2).' left',
            ])
            ->all();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('agreement')
                ->label('Agreement')
                ->icon('heroicon-o-document-check')
                ->color('gray')
                ->visible(fn (): bool => $this->getRecord()->agreement() !== null)
                ->url(fn (): ?string => $this->getRecord()->agreement()?->url(), shouldOpenInNewTab: true),
            Action::make('makePayment')
                ->label('Make payment')
                ->icon('heroicon-o-qr-code')
                ->visible(fn (): bool => $this->record->fee !== null && $this->record->balanceDue() > 0)
                ->modalHeading('Make a payment')
                ->modalDescription('Pay using the details below, then enter the transaction reference so we can verify it.')
                ->modalContent(fn () => view('filament.modals.payment-qr'))
                ->form([
                    // Only milestones that carry a share of the fee can be paid against; without any, the field is hidden.
                    Select::make('milestone_id')
                        ->label('For milestone (optional)')
                        ->options(fn (): array => $this->billableMilestoneOptions())
                        ->visible(fn (): bool => $this->billableMilestoneOptions() !== [])
                        ->in(fn (): array => $this->record->milestones()->pluck('id')->all())
                        ->live()
                        ->afterStateUpdated(function (Set $set, $state): void {
                            $left = $this->record->milestones()->find($state)?->amountLeft();

                            if ($left > 0) {
                                $set('amount', $left);
                            }
                        }),
                    TextInput::make('amount')
                        ->label('Amount paid')
                        ->numeric()
                        ->required()
                        ->prefix('NPR')
                        ->default(fn (): ?float => $this->record->amountDueNow() ?: null)
                        ->rules([
                            fn (Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($get): void {
                                $pending = $this->record->paymentSubmissions()->where('status', 'pending');
                                $milestone = $this->record->milestones()->find($get('milestone_id'));

                                $error = Payment::amountError($this->record, (float) $value, alsoReserved: (float) $pending->clone()->sum('amount'))
                                    ?? Payment::milestoneAmountError($milestone, (float) $value, alsoReserved: (float) $pending->clone()->where('milestone_id', $milestone?->id)->sum('amount'));

                                if ($error !== null) {
                                    $fail($pending->clone()->exists()
                                        ? $error.' Payments waiting for verification are included.'
                                        : $error);
                                }
                            },
                        ]),
                    TextInput::make('transaction_reference')
                        ->label('Transaction ID / reference')
                        ->required()
                        ->maxLength(255),
                    FileUpload::make('screenshot_path')
                        ->label('Payment screenshot (optional)')
                        ->image()
                        ->maxSize(5120)
                        ->disk('public')
                        ->directory('project-payment-screenshots'),
                ])
                ->action(function (array $data): void {
                    ProjectPaymentSubmission::create([
                        ...$data,
                        'project_id' => $this->record->id,
                        'submitted_by' => auth()->id(),
                    ]);

                    Notification::make()
                        ->title('Payment submitted for verification')
                        ->body('We will confirm it shortly. You can follow it under Payment submissions.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
