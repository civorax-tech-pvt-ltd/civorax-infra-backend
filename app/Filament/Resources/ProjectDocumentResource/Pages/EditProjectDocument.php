<?php

namespace App\Filament\Resources\ProjectDocumentResource\Pages;

use App\Filament\Resources\ProjectDocumentResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditProjectDocument extends EditRecord
{
    protected static string $resource = ProjectDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
            Actions\ForceDeleteAction::make(),
            Actions\RestoreAction::make(),
        ];
    }
}
