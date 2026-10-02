<?php

namespace App\Filament\Resources\BoqCategoryResource\Pages;

use App\Filament\Resources\BoqCategoryResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageBoqCategories extends ManageRecords
{
    protected static string $resource = BoqCategoryResource::class;

    protected ?string $subheading = 'Used to group BOQ library items. Drag rows to change the order in the dropdown. A category in use can be renamed but not deleted.';

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('New category'),
        ];
    }
}
