<?php

namespace App\Filament\Resources\SiteReportResource\Pages;

use App\Filament\Resources\SiteReportResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSiteReport extends EditRecord
{
    protected static string $resource = SiteReportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
