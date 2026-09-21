<?php

namespace App\Filament\Resources\CoursePaymentSubmissionResource\Pages;

use App\Filament\Resources\CoursePaymentSubmissionResource;
use Filament\Resources\Pages\ListRecords;

class ListCoursePaymentSubmissions extends ListRecords
{
    protected static string $resource = CoursePaymentSubmissionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
