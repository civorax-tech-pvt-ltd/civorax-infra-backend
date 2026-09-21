<?php

namespace App\Filament\Resources\CoursePaymentResource\Pages;

use App\Filament\Resources\CoursePaymentResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCoursePayments extends ListRecords
{
    protected static string $resource = CoursePaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
