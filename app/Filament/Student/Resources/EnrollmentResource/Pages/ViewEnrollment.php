<?php

namespace App\Filament\Student\Resources\EnrollmentResource\Pages;

use App\Filament\Student\Resources\EnrollmentResource;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewEnrollment extends ViewRecord
{
    protected static string $resource = EnrollmentResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                TextEntry::make('course.title')->label('Course'),
                TextEntry::make('course.type')->label('Mode'),
                TextEntry::make('course.duration')->label('Duration'),
                TextEntry::make('course.description')->columnSpanFull(),
                TextEntry::make('enrolled_at')->date(),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
