<?php

namespace App\Filament\Resources\ProjectResource\Pages;

use App\Filament\Resources\ProjectResource;
use App\Models\Project;
use Filament\Actions;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
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
            Actions\Action::make('agreement')
                ->label(fn (Project $record): string => $record->agreement() ? 'Agreement (new version)' : 'Upload agreement')
                ->icon('heroicon-o-document-check')
                ->color('gray')
                ->modalDescription('Shared with the client: it appears in their Documents and as a download button on their project page.')
                ->form([
                    TextInput::make('title')->default('Project agreement')->required()->maxLength(255),
                    FileUpload::make('file_path')
                        ->label('Signed agreement (PDF or photo)')
                        ->disk('public')
                        ->directory('agreements')
                        ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])
                        ->maxSize(20480)
                        ->required(),
                ])
                ->action(function (Project $record, array $data): void {
                    $record->documents()->create([
                        'title' => $data['title'],
                        'type' => 'agreement',
                        'file_path' => $data['file_path'],
                        'version' => ((int) $record->documents()->where('type', 'agreement')->max('version')) + 1,
                        'uploaded_by' => auth()->id(),
                    ]);

                    Notification::make()->title('Agreement shared with the client')->success()->send();
                }),
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
