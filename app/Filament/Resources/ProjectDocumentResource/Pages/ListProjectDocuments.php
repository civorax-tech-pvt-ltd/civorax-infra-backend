<?php

namespace App\Filament\Resources\ProjectDocumentResource\Pages;

use App\Filament\Resources\ProjectDocumentResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListProjectDocuments extends ListRecords
{
    protected static string $resource = ProjectDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
