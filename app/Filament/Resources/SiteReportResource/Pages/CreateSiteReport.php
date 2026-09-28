<?php

namespace App\Filament\Resources\SiteReportResource\Pages;

use App\Filament\Resources\SiteReportResource;
use App\Models\SiteReport;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;

class CreateSiteReport extends CreateRecord
{
    protected static string $resource = SiteReportResource::class;

    protected bool $submitAfterCreate = false;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return [...$data, 'status' => 'draft', 'submitted_by' => auth()->id()];
    }

    protected function afterCreate(): void
    {
        if ($this->submitAfterCreate) {
            /** @var SiteReport $report */
            $report = $this->getRecord();
            $report->submit(auth()->user());
        }
    }

    public function createAndSubmit(): void
    {
        $this->submitAfterCreate = true;
        $this->create();
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('createAndSubmit')
                ->label('Submit report')
                ->icon('heroicon-o-paper-airplane')
                ->action('createAndSubmit'),
            $this->getCreateFormAction()->label('Save draft')->color('gray'),
            $this->getCancelFormAction(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
