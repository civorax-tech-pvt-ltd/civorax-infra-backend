<?php

namespace App\Filament\Resources\ProjectResource\Pages;

use App\Filament\Resources\ProjectResource;
use App\Models\Project;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditProject extends EditRecord
{
    protected static string $resource = ProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('costs')
                ->label('Costs & profit')
                ->icon('heroicon-o-chart-pie')
                ->color('gray')
                ->visible(fn (): bool => ProjectResource::canViewCosts())
                ->url(fn (Project $record): string => ProjectResource::getUrl('costs', ['record' => $record])),
            Actions\Action::make('applyMilestoneTemplates')
                ->label('Add milestones from template')
                ->icon('heroicon-o-queue-list')
                ->visible(fn (Project $record): bool => $record->milestones()->doesntExist()
                    && $record->projectType?->milestoneTemplates()->exists() === true)
                ->requiresConfirmation()
                ->modalDescription(fn (Project $record): string => "Creates the standard milestones and tasks for {$record->projectType->name} projects.")
                ->action(function (Project $record): void {
                    $count = $record->applyMilestoneTemplates();

                    Notification::make()
                        ->title("Added {$count} milestones with their tasks")
                        ->success()
                        ->send();

                    $record->refresh();
                    $this->refreshFormData(['status']);
                }),
            Actions\DeleteAction::make(),
            Actions\ForceDeleteAction::make(),
            Actions\RestoreAction::make(),
        ];
    }
}
