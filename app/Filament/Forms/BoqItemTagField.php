<?php

namespace App\Filament\Forms;

use App\Models\BoqItem;
use App\Models\Project;
use Filament\Forms\Components\Select;
use Filament\Forms\Get;

/**
 * Optional "BOQ item" tag for a direct cost, shown only when the project tracks cost per BOQ item.
 */
class BoqItemTagField
{
    public static function make(string $projectField = 'project_id', string $label = 'BOQ item (optional)'): Select
    {
        $project = fn (Get $get): ?Project => filled($get($projectField)) ? Project::find($get($projectField)) : null;

        return Select::make('boq_item_id')
            ->label($label)
            ->options(fn (Get $get): array => BoqItem::query()
                ->where('project_id', $get($projectField))
                ->orderBy('sort')
                ->get()
                ->mapWithKeys(fn (BoqItem $item): array => [$item->id => $item->label()])
                ->all())
            ->searchable()
            ->placeholder('Not tied to one item')
            ->visible(fn (Get $get): bool => (bool) $project($get)?->track_item_costs)
            ->helperText('Charges this cost to one BOQ item for the cost-per-item report. Leave empty for shared costs.');
    }
}
