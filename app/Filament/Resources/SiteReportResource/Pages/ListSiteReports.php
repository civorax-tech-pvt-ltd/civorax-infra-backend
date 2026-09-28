<?php

namespace App\Filament\Resources\SiteReportResource\Pages;

use App\Filament\Resources\SiteReportResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSiteReports extends ListRecords
{
    protected static string $resource = SiteReportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('New site report'),
        ];
    }
}
