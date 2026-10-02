<?php

namespace App\Filament\Resources\BoqMasterItemResource\Pages;

use App\Filament\Resources\BoqMasterItemResource;
use App\Models\BoqSheet;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;

class ManageBoqMasterItems extends ManageRecords
{
    protected static string $resource = BoqMasterItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('New library item'),
            Actions\ActionGroup::make([
                Actions\Action::make('export')
                    ->label('Export to Excel (CSV)')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(fn () => BoqSheet::exportLibrary()),
                Actions\Action::make('import')
                    ->label('Import from file')
                    ->icon('heroicon-o-arrow-up-tray')
                    // Updates existing items' rates, so admins only.
                    ->visible(fn (): bool => Filament::getCurrentPanel()?->getId() === 'admin')
                    ->modalDescription('Upload a CSV file (in Excel: File › Save As › CSV). Columns: Code, Category, Description, Unit, Rate, Rate includes supplier VAT, Active. Items are matched by code (or by description and unit): existing ones are updated, new ones added. New categories are created automatically.')
                    ->modalSubmitActionLabel('Import')
                    ->form([
                        Forms\Components\FileUpload::make('file')
                            ->label('CSV file')
                            ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel', 'application/csv'])
                            ->storeFiles(false)
                            ->required(),
                    ])
                    ->extraModalFooterActions([
                        Actions\Action::make('template')
                            ->label('Download template')
                            ->color('gray')
                            ->action(fn () => BoqSheet::template('library')),
                    ])
                    ->action(function (array $data): void {
                        $result = BoqSheet::importLibrary(BoqSheet::read($data['file']->getRealPath()), auth()->user());

                        Notification::make()
                            ->title("Imported: {$result['created']} new, {$result['updated']} updated")
                            ->body($result['skipped'] ? count($result['skipped']).' skipped · '.implode(' ', array_slice($result['skipped'], 0, 5)) : null)
                            ->color($result['skipped'] ? 'warning' : 'success')
                            ->icon('heroicon-o-arrow-up-tray')
                            ->persistent()
                            ->send();
                    }),
            ])->label('Import / export')->icon('heroicon-o-table-cells')->button()->color('gray'),
        ];
    }
}
