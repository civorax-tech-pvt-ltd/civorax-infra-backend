<?php

namespace App\Filament\Resources\PortfolioProjectResource\Pages;

use App\Filament\Resources\PortfolioProjectResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePortfolioProject extends CreateRecord
{
    protected static string $resource = PortfolioProjectResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Team members' projects start as drafts and go live only after review.
        if (! PortfolioProjectResource::canPublish()) {
            $data['is_published'] = false;
            unset($data['published_at']);
        }

        return [...$data, 'created_by' => auth()->id()];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return PortfolioProjectResource::canPublish() ? parent::getCreatedNotificationTitle() : 'Draft saved. Press “Submit for review” when it is ready.';
    }
}
