<?php

namespace App\Filament\Resources\PortfolioProjectResource\Pages;

use App\Filament\Resources\PortfolioProjectResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPortfolioProjects extends ListRecords
{
    protected static string $resource = PortfolioProjectResource::class;

    protected ?string $subheading = 'Projects shown on the website\'s Our Work. Drag rows to change their order.';

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('New portfolio project')];
    }
}
