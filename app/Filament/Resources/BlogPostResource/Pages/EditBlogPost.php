<?php

namespace App\Filament\Resources\BlogPostResource\Pages;

use App\Filament\Resources\BlogPostResource;
use App\Models\BlogPost;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

/**
 * @property BlogPost $record
 */
class EditBlogPost extends EditRecord
{
    protected static string $resource = BlogPostResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (! BlogPostResource::canPublish()) {
            unset($data['published_at'], $data['is_featured'], $data['status']);
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('submit')
                ->label('Submit for review')
                ->icon('heroicon-o-paper-airplane')
                ->visible(fn (): bool => ! BlogPostResource::canPublish() && in_array($this->record->status, ['draft', 'changes_requested'], true))
                ->requiresConfirmation()
                ->modalDescription('Your latest changes are saved first. You can\'t edit the post while it is being reviewed.')
                ->action(function (): void {
                    $this->save(shouldRedirect: false, shouldSendSavedNotification: false);
                    $this->record->submit(auth()->user());

                    Notification::make()->title('Sent for review')->body('You\'ll be notified when it is published or if changes are needed.')->success()->send();
                    $this->redirect(BlogPostResource::getUrl());
                }),
            Actions\Action::make('publish')
                ->label(fn (): string => $this->record->status === 'published' ? 'Reschedule' : 'Publish')
                ->icon('heroicon-o-globe-alt')
                ->color('success')
                ->visible(fn (): bool => BlogPostResource::canPublish() && ($this->record->status !== 'published' || $this->record->published_at?->isFuture()))
                ->modalHeading('Publish on the website')
                ->modalDescription('Your latest changes are saved first.')
                ->form([
                    Forms\Components\DateTimePicker::make('published_at')
                        ->label('Publish date')
                        ->seconds(false)
                        ->default(fn (): ?string => $this->record->published_at?->isFuture() ? $this->record->published_at->toDateTimeString() : null)
                        ->helperText('Leave empty to publish now. A future date schedules it.'),
                ])
                ->action(function (array $data): void {
                    $this->save(shouldRedirect: false, shouldSendSavedNotification: false);
                    $this->record->publish(auth()->user(), $data['published_at'] ?? now()->toDateTimeString());

                    Notification::make()->title($this->record->published_at->isFuture() ? 'Scheduled' : 'Published')->success()->send();
                    $this->redirect(BlogPostResource::getUrl('edit', ['record' => $this->record]));
                }),
            Actions\Action::make('requestChanges')
                ->label('Request changes')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('warning')
                ->visible(fn (): bool => BlogPostResource::canPublish() && $this->record->status === 'pending')
                ->form([
                    Forms\Components\Textarea::make('note')
                        ->label('What should the writer change?')
                        ->required()
                        ->rows(4),
                ])
                ->action(function (array $data): void {
                    $this->record->requestChanges(auth()->user(), $data['note']);

                    Notification::make()->title('Sent back to the writer')->success()->send();
                    $this->redirect(BlogPostResource::getUrl());
                }),
            Actions\Action::make('unpublish')
                ->label('Unpublish')
                ->icon('heroicon-o-eye-slash')
                ->color('gray')
                ->visible(fn (): bool => BlogPostResource::canPublish() && $this->record->status === 'published')
                ->requiresConfirmation()
                ->modalDescription('The post is removed from the website and goes back to drafts.')
                ->action(function (): void {
                    $this->record->unpublish();

                    Notification::make()->title('Unpublished')->success()->send();
                    $this->redirect(BlogPostResource::getUrl('edit', ['record' => $this->record]));
                }),
            Actions\Action::make('view')
                ->label('View on website')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('gray')
                ->visible(fn (): bool => $this->record->status === 'published')
                ->url(fn (): ?string => BlogPostResource::websiteUrl($this->record), shouldOpenInNewTab: true),
            Actions\DeleteAction::make(),
        ];
    }
}
