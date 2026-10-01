<?php

namespace App\Filament\Resources\BlogPostResource\Pages;

use App\Filament\Resources\BlogPostResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * New posts always start as drafts; the edit page then offers "Submit for review" or (approvers) "Publish".
 */
class CreateBlogPost extends CreateRecord
{
    protected static string $resource = BlogPostResource::class;

    protected function afterFill(): void
    {
        $defaults = BlogPostResource::authorDefaults(auth()->user());
        unset($defaults['author_id']);

        $this->data = [...$this->data, ...$defaults];
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['author_id'] = auth()->id();
        $data['status'] = 'draft';

        if (! BlogPostResource::canPublish()) {
            unset($data['published_at'], $data['is_featured']);
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return BlogPostResource::canPublish()
            ? 'Draft saved. Press “Publish” when it is ready.'
            : 'Draft saved. Press “Submit for review” when it is ready.';
    }
}
