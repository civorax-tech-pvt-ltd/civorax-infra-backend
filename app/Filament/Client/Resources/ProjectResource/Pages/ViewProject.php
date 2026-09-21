<?php

namespace App\Filament\Client\Resources\ProjectResource\Pages;

use App\Filament\Client\Resources\ProjectResource;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewProject extends ViewRecord
{
    protected static string $resource = ProjectResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                TextEntry::make('title'),
                TextEntry::make('projectType.name')->label('Type'),
                TextEntry::make('description')->columnSpanFull(),
                TextEntry::make('site_address'),
                TextEntry::make('city'),
                TextEntry::make('ward_no')->label('Ward No.'),
                TextEntry::make('status')->badge(),
                TextEntry::make('fee')->money('NPR'),
                TextEntry::make('estimated_end_date')->date(),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
