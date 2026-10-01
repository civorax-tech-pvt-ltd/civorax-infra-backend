<?php

namespace App\Filament\Client\Resources\WorkProgressResource\Pages;

use App\Filament\Client\Resources\WorkProgressResource;
use Filament\Resources\Pages\ListRecords;

class ListWorkProgress extends ListRecords
{
    protected static string $resource = WorkProgressResource::class;

    protected ?string $subheading = 'How much of each work item is done on site.';
}
