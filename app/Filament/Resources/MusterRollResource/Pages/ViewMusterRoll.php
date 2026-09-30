<?php

namespace App\Filament\Resources\MusterRollResource\Pages;

use App\Filament\Resources\MusterRollResource;
use App\Models\LabourContractor;
use App\Models\MusterRoll;
use App\Models\WagePayment;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ViewMusterRoll extends ViewRecord
{
    protected static string $resource = MusterRollResource::class;

    public function getTitle(): string
    {
        return "Muster Roll · {$this->getRoll()->label()}";
    }

    public function getSubheading(): ?string
    {
        return $this->getRoll()->project->title;
    }

    protected function getRoll(): MusterRoll
    {
        return $this->getRecord();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('refresh')
                ->label('Refresh from attendance')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn (): bool => $this->getRoll()->isEditable())
                ->action(function (): void {
                    $this->getRoll()->rebuildLines();
                    Notification::make()->title('Updated from the latest labour attendance')->success()->send();
                }),
            Actions\Action::make('submit')
                ->label('Submit for approval')
                ->icon('heroicon-o-paper-airplane')
                ->visible(fn (): bool => $this->getRoll()->isEditable())
                ->requiresConfirmation()
                ->modalDescription('Attendance for this month is locked once submitted, until an approver returns the roll.')
                ->action(function (): void {
                    try {
                        $this->getRoll()->submit(auth()->user());
                    } catch (ValidationException $exception) {
                        Notification::make()->title(collect($exception->errors())->flatten()->first())->danger()->send();

                        return;
                    }

                    Notification::make()->title('Submitted for approval')->success()->send();
                    $this->reloadPage();
                }),
            Actions\Action::make('approve')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool => $this->getRoll()->status === 'submitted' && MusterRollResource::canApproveSite())
                ->form([
                    Forms\Components\Textarea::make('note')->label('Note (optional)'),
                ])
                ->action(function (array $data): void {
                    $this->getRoll()->approve(auth()->user(), $data['note'] ?? null);
                    Notification::make()->title('Muster roll approved. Wages are now payable.')->success()->send();
                    $this->reloadPage();
                }),
            Actions\Action::make('return')
                ->label('Return for changes')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('danger')
                ->visible(fn (): bool => $this->getRoll()->isLocked() && MusterRollResource::canApproveSite())
                ->form([
                    Forms\Components\Textarea::make('note')
                        ->label('What needs to change?')
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $this->getRoll()->returnForChanges(auth()->user(), $data['note']);
                    Notification::make()->title('Returned. Attendance for this month is unlocked.')->warning()->send();
                    $this->reloadPage();
                }),
            $this->payWagesAction(),
            Actions\Action::make('print')
                ->label('Print / PDF')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->url(fn (): string => route('site.muster-rolls.print', $this->getRoll()), shouldOpenInNewTab: true),
            Actions\EditAction::make()
                ->label(fn (): string => $this->getRoll()->isEditable() ? 'Edit Part II / III' : 'Edit reasons'),
            MusterRollResource::deleteAction(Actions\DeleteAction::make()),
        ];
    }

    /**
     * Header buttons are decided when the page loads, so reload after a status change to show the right ones.
     */
    protected function reloadPage(): void
    {
        $this->redirect(MusterRollResource::getUrl('view', ['record' => $this->getRoll()]));
    }

    protected function payWagesAction(): Actions\Action
    {
        return Actions\Action::make('payWages')
            ->label('Pay wages')
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->visible(fn (): bool => $this->getRoll()->status === 'approved' && MusterRollResource::canPayWages())
            ->modalWidth('3xl')
            ->modalDescription('Amounts owed on this site include earlier months and are already reduced by any advances given.')
            ->fillForm(fn (): array => [
                'paid_on' => now(config('app.business_timezone'))->toDateString(),
                'method' => 'cash',
                'payments' => MusterRollResource::payableRows($this->getRoll()),
            ])
            ->form([
                Forms\Components\Grid::make(4)->schema([
                    Forms\Components\DatePicker::make('paid_on')
                        ->required()
                        ->maxDate(now(config('app.business_timezone'))->toDateString()),
                    Forms\Components\Select::make('method')
                        ->options(WagePayment::METHODS)
                        ->required(),
                    Forms\Components\TextInput::make('reference')
                        ->placeholder('Voucher / txn no.'),
                    Forms\Components\Select::make('labour_contractor_id')
                        ->label('Handed to naike')
                        ->placeholder('Paid to each labourer')
                        ->options(fn (): array => LabourContractor::query()
                            ->whereHas('labourers', fn ($query) => $query->whereIn('labourers.id', $this->getRoll()->lines()->select('labourer_id')))
                            ->pluck('name', 'id')
                            ->all())
                        ->live()
                        ->afterStateUpdated(fn (Set $set, $state) => $set('payments', MusterRollResource::payableRows($this->getRoll(), $state))),
                ]),
                Forms\Components\Repeater::make('payments')
                    ->hiddenLabel()
                    ->addable(false)
                    ->reorderable(false)
                    ->columns(3)
                    ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                    ->schema([
                        Forms\Components\Hidden::make('labourer_id'),
                        Forms\Components\Hidden::make('name'),
                        Forms\Components\Placeholder::make('owed')
                            ->content(fn (Get $get): string => 'Rs '.number_format((float) $get('due'), 2)),
                        Forms\Components\Hidden::make('due'),
                        Forms\Components\TextInput::make('amount')
                            ->label('Paying now')
                            ->numeric()
                            ->prefix('Rs')
                            ->minValue(0)
                            ->required()
                            ->helperText('Pay more than owed to give an advance.'),
                    ])
                    ->helperText('Remove anyone you are not paying today.'),
            ])
            ->action(function (array $data): void {
                $roll = $this->getRoll();
                $rows = collect($data['payments'] ?? [])->filter(fn (array $row): bool => (float) $row['amount'] > 0);

                if ($rows->isEmpty()) {
                    Notification::make()->title('Nothing to pay')->warning()->send();

                    return;
                }

                DB::transaction(fn () => $rows->each(function (array $row) use ($roll, $data): void {
                    $wage = min((float) $row['amount'], (float) $row['due']);

                    // Anything above what is owed is recorded separately as an advance.
                    foreach (['wage' => $wage, 'advance' => (float) $row['amount'] - $wage] as $type => $amount) {
                        if ($amount <= 0) {
                            continue;
                        }

                        WagePayment::create([
                            'project_id' => $roll->project_id,
                            'labourer_id' => $row['labourer_id'],
                            'labour_contractor_id' => $data['labour_contractor_id'] ?? null,
                            'muster_roll_id' => $roll->getKey(),
                            'type' => $type,
                            'amount' => round($amount, 2),
                            'method' => $data['method'],
                            'reference' => $data['reference'] ?? null,
                            'paid_on' => $data['paid_on'],
                            'paid_by' => auth()->id(),
                        ]);
                    }
                }));

                Notification::make()
                    ->title('Paid Rs '.number_format($rows->sum(fn (array $row): float => (float) $row['amount']), 2).' to '.$rows->count().' '.str('labourer')->plural($rows->count()))
                    ->success()
                    ->send();
            });
    }
}
