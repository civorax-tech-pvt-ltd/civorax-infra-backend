<?php

namespace App\Filament\Resources\CoursePaymentResource\Pages;

use App\Filament\Resources\CoursePaymentResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCoursePayment extends EditRecord
{
    protected static string $resource = CoursePaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
            Actions\ForceDeleteAction::make(),
            Actions\RestoreAction::make(),
        ];
    }
}
