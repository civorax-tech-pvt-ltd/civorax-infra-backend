<?php

namespace App\Filament\Resources\LabourContractorResource\Pages;

use App\Filament\Resources\LabourContractorResource;
use App\Models\LabourContractor;
use Filament\Actions;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * A naike's gang at a glance: what each labourer is owed or holds, and cash handed to the naike.
 */
class NaikeAccount extends Page
{
    use InteractsWithRecord;

    protected static string $resource = LabourContractorResource::class;

    protected static string $view = 'filament.pages.naike-account';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(LabourContractorResource::canSeeWages(), 403);
    }

    public function getTitle(): string|Htmlable
    {
        return "Naike account · {$this->getContractor()->name}";
    }

    public function getContractor(): LabourContractor
    {
        return $this->getRecord();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('print')
                ->label('Print')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->url(fn (): string => route('site.naikes.ledger', $this->getContractor()), shouldOpenInNewTab: true),
        ];
    }
}
