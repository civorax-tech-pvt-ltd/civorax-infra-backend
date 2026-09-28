<?php

namespace App\Filament\Resources\SiteReportResource\Pages;

use App\Filament\Resources\SiteReportResource;
use App\Models\SiteReport;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewSiteReport extends ViewRecord
{
    protected static string $resource = SiteReportResource::class;

    public function getTitle(): string
    {
        return 'Site report · '.$this->getReport()->date->format('D, M j, Y');
    }

    public function getSubheading(): ?string
    {
        return $this->getReport()->project->title;
    }

    protected function getReport(): SiteReport
    {
        return $this->getRecord();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('submit')
                ->label('Submit for review')
                ->icon('heroicon-o-paper-airplane')
                ->visible(fn (): bool => $this->getReport()->isEditable())
                ->action(function (): void {
                    $this->getReport()->submit(auth()->user());
                    Notification::make()->title('Submitted for review')->success()->send();
                }),
            Actions\Action::make('approve')
                ->label('Approve & share with client')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool => $this->getReport()->status === 'submitted' && SiteReportResource::canApproveSite())
                ->form([
                    Forms\Components\Textarea::make('note')->label('Note (optional)'),
                ])
                ->action(function (array $data): void {
                    $this->getReport()->approve(auth()->user(), $data['note'] ?? null);
                    Notification::make()->title('Approved. The client can now see this report.')->success()->send();
                }),
            Actions\Action::make('return')
                ->label('Return for changes')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('danger')
                ->visible(fn (): bool => in_array($this->getReport()->status, ['submitted', 'approved'], true) && SiteReportResource::canApproveSite())
                ->form([
                    Forms\Components\Textarea::make('note')
                        ->label('What needs to change?')
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $this->getReport()->returnForChanges(auth()->user(), $data['note']);
                    Notification::make()->title('Returned to the site team (hidden from the client)')->warning()->send();
                }),
            Actions\EditAction::make(),
        ];
    }
}
