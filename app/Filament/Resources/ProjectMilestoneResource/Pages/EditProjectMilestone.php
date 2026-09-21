<?php

namespace App\Filament\Resources\ProjectMilestoneResource\Pages;

use App\Filament\Resources\ProjectMilestoneResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditProjectMilestone extends EditRecord
{
    protected static string $resource = ProjectMilestoneResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
            Actions\ForceDeleteAction::make(),
            Actions\RestoreAction::make(),
        ];
    }
}
