<?php

namespace App\Filament\Resources\AttendanceMonthlySummaryResource\Pages;

use App\Filament\Resources\AttendanceMonthlySummaryResource;
use App\Models\AttendanceMonthlySummary;
use Filament\Resources\Pages\ListRecords;

class ListAttendanceMonthlySummaries extends ListRecords
{
    protected static string $resource = AttendanceMonthlySummaryResource::class;

    public function getSubheading(): ?string
    {
        return 'Kept permanently. Day-by-day detail and GPS readings are kept from '
            .AttendanceMonthlySummary::retentionCutoff()->format('F Y').' onward.';
    }
}
