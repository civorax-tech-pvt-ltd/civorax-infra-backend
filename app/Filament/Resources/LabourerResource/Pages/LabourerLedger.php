<?php

namespace App\Filament\Resources\LabourerResource\Pages;

use App\Filament\Resources\LabourerResource;
use App\Filament\Resources\WagePaymentResource;
use App\Models\Labourer;
use App\Models\Project;
use App\Models\WagePayment;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;

/**
 * A labourer's khata: every approved month's wages and every payment across all sites, with a running balance.
 */
class LabourerLedger extends Page
{
    use InteractsWithRecord;

    protected static string $resource = LabourerResource::class;

    protected static string $view = 'filament.pages.labourer-ledger';

    /**
     * @var array{project_id?: int|string|null, from?: string|null, until?: string|null}
     */
    public array $filters = [];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(LabourerResource::canSeeWages(), 403);

        $this->form->fill();
    }

    public function getTitle(): string
    {
        return "Ledger · {$this->getLabourer()->name}";
    }

    public function getSubheading(): ?string
    {
        $labourer = $this->getLabourer();

        return collect([
            $labourer->workTypeLabel(),
            'Rs '.number_format((float) $labourer->daily_wage).'/day',
            $labourer->father_name ? "s/o {$labourer->father_name}" : null,
            $labourer->contractor ? "via {$labourer->contractor->name}" : null,
        ])->filter()->implode(' · ');
    }

    public function getLabourer(): Labourer
    {
        return $this->getRecord();
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('filters')
            ->columns(['default' => 1, 'sm' => 3])
            ->schema([
                Forms\Components\Select::make('project_id')
                    ->label('Site')
                    ->placeholder('All sites')
                    ->options(fn (): array => Project::query()
                        ->where(fn ($query) => $query
                            ->whereHas('labourAttendances', fn ($query) => $query->where('labourer_id', $this->getLabourer()->id))
                            ->orWhereHas('wagePayments', fn ($query) => $query->where('labourer_id', $this->getLabourer()->id)))
                        ->orderBy('title')
                        ->pluck('title', 'id')
                        ->all())
                    ->live(),
                Forms\Components\DatePicker::make('from')->live(),
                Forms\Components\DatePicker::make('until')->live(),
            ]);
    }

    /**
     * @return array{opening: float, rows: list<array<string, mixed>>, credit: float, debit: float, closing: float}
     */
    public function ledger(): array
    {
        return $this->getLabourer()->ledger(
            filled($this->filters['project_id'] ?? null) ? (int) $this->filters['project_id'] : null,
            $this->filters['from'] ?? null,
            $this->filters['until'] ?? null,
        );
    }

    protected function getHeaderActions(): array
    {
        $labourer = $this->getLabourer();

        return [
            // A create action so the form has a model and its site / naike pickers can load their options.
            Actions\CreateAction::make('recordEntry')
                ->label('Pay / advance / return')
                ->icon('heroicon-o-banknotes')
                ->model(WagePayment::class)
                ->modalHeading("Money to or from {$labourer->name}")
                ->modalDescription(fn (): string => WagePaymentResource::balanceText($labourer->refresh()))
                ->createAnother(false)
                ->visible(fn (): bool => LabourerResource::canPayWages())
                ->form(WagePaymentResource::paymentFields($labourer))
                ->fillForm(fn (): array => [
                    'type' => $labourer->balance() > 0 ? 'wage' : 'advance',
                    'amount' => max(0, $labourer->balance()) ?: null,
                    'paid_on' => now(config('app.business_timezone'))->toDateString(),
                    'method' => 'cash',
                    'labourer_id' => $labourer->id,
                    'labour_contractor_id' => null,
                    'paid_by' => auth()->id(),
                ])
                ->successNotificationTitle('Recorded in the ledger'),
            Actions\Action::make('print')
                ->label('Print')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->url(fn (): string => route('site.labourers.ledger', ['labourer' => $labourer, ...array_filter($this->filters)]), shouldOpenInNewTab: true),
        ];
    }
}
