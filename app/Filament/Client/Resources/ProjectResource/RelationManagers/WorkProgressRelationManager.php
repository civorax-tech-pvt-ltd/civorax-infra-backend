<?php

namespace App\Filament\Client\Resources\ProjectResource\RelationManagers;

use App\Filament\Client\Resources\WorkProgressResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The project's BOQ progress (quantities only), visible only when the admin shares it.
 */
class WorkProgressRelationManager extends RelationManager
{
    protected static string $relationship = 'boqItems';

    protected static ?string $title = 'Work Progress';

    protected static ?string $icon = 'heroicon-o-chart-bar-square';

    public function table(Table $table): Table
    {
        return WorkProgressResource::progressTable($table)
            ->description(fn (): string => 'Overall: '.($this->getOwnerRecord()->boqProgress() ?? 0).'% of the work done (by value).')
            ->columns(WorkProgressResource::progressColumns())
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) $ownerRecord->share_boq_with_client;
    }
}
