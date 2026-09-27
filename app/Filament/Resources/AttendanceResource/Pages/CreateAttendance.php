<?php

namespace App\Filament\Resources\AttendanceResource\Pages;

use App\Filament\Resources\AttendanceResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Carbon;

class CreateAttendance extends CreateRecord
{
    protected static string $resource = AttendanceResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return [
            ...$data,
            'date' => Carbon::parse($data['first_seen_at'])->timezone(config('app.business_timezone'))->toDateString(),
            'source' => 'manual',
            'recorded_by' => auth()->id(),
        ];
    }
}
