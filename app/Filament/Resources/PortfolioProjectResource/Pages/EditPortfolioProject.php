<?php

namespace App\Filament\Resources\PortfolioProjectResource\Pages;

use App\Filament\Resources\PortfolioProjectResource;
use App\Models\PortfolioProject;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

/**
 * @property PortfolioProject $record
 */
class EditPortfolioProject extends EditRecord
{
    protected static string $resource = PortfolioProjectResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Only approvers decide what is live.
        if (! PortfolioProjectResource::canPublish()) {
            unset($data['is_published'], $data['published_at']);
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('submit')
                ->label('Submit for review')
                ->icon('heroicon-o-paper-airplane')
                ->visible(fn (): bool => ! PortfolioProjectResource::canPublish() && in_array($this->record->review_status, ['draft', 'changes_requested'], true))
                ->requiresConfirmation()
                ->modalDescription('Your latest changes are saved first. You can\'t edit the project while it is being reviewed.')
                ->action(function (): void {
                    $this->save(shouldRedirect: false, shouldSendSavedNotification: false);
                    $this->record->submit(auth()->user());

                    Notification::make()->title('Sent for review')->body('You\'ll be notified when it is published or if changes are needed.')->success()->send();
                    $this->redirect(PortfolioProjectResource::getUrl());
                }),
            Actions\Action::make('publish')
                ->label(fn (): string => $this->record->review_status === 'pending' ? 'Approve & publish' : 'Publish')
                ->icon('heroicon-o-globe-alt')
                ->color('success')
                ->visible(fn (): bool => PortfolioProjectResource::canPublish() && ! $this->record->is_published)
                ->modalHeading('Publish on Our Work')
                ->modalDescription('Your latest changes are saved first.')
                ->form([
                    Forms\Components\DateTimePicker::make('published_at')
                        ->label('Publish date')
                        ->seconds(false)
                        ->helperText('Leave empty to publish now. A future date schedules it.'),
                ])
                ->action(function (array $data): void {
                    $this->save(shouldRedirect: false, shouldSendSavedNotification: false);
                    $this->record->publish(auth()->user(), $data['published_at'] ?? null);

                    Notification::make()->title($this->record->published_at->isFuture() ? 'Scheduled' : 'Published')->success()->send();
                    $this->redirect(PortfolioProjectResource::getUrl('edit', ['record' => $this->record]));
                }),
            Actions\Action::make('requestChanges')
                ->label('Request changes')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('warning')
                ->visible(fn (): bool => PortfolioProjectResource::canPublish() && $this->record->review_status === 'pending')
                ->form([
                    Forms\Components\Textarea::make('note')
                        ->label('What should be changed?')
                        ->required()
                        ->rows(4),
                ])
                ->action(function (array $data): void {
                    $this->record->requestChanges(auth()->user(), $data['note']);

                    Notification::make()->title('Sent back')->success()->send();
                    $this->redirect(PortfolioProjectResource::getUrl());
                }),
            Actions\Action::make('unpublish')
                ->label('Unpublish')
                ->icon('heroicon-o-eye-slash')
                ->color('gray')
                ->visible(fn (): bool => PortfolioProjectResource::canPublish() && $this->record->is_published)
                ->requiresConfirmation()
                ->modalDescription('The project is removed from the website and goes back to drafts.')
                ->action(function (): void {
                    $this->record->unpublish();

                    Notification::make()->title('Unpublished')->success()->send();
                    $this->redirect(PortfolioProjectResource::getUrl('edit', ['record' => $this->record]));
                }),
            Actions\Action::make('view')
                ->label('View on website')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('gray')
                ->visible(fn (): bool => $this->record->is_published)
                ->url(fn (): ?string => PortfolioProjectResource::websiteUrl($this->record), shouldOpenInNewTab: true),
            Actions\DeleteAction::make(),
            Actions\RestoreAction::make(),
        ];
    }
}
