<?php

namespace App\Filament\Resources\CertificateResource\Pages;

use App\Filament\Resources\CertificateResource;
use App\Models\Enrollment;
use Filament\Resources\Pages\CreateRecord;

class CreateCertificate extends CreateRecord
{
    protected static string $resource = CertificateResource::class;

    protected static ?string $title = 'Issue certificate';

    /**
     * Opened from an enrollment's "Certificate" button: start with that student and course filled in.
     */
    protected function afterFill(): void
    {
        $enrollment = Enrollment::with(['student', 'course'])->doesntHave('certificate')->find(request()->integer('enrollment'));

        if ($enrollment !== null) {
            $this->form->fill([
                ...$this->form->getRawState(),
                'enrollment_id' => $enrollment->id,
                'student_name' => $enrollment->student?->fullname,
                'course_title' => $enrollment->course?->title,
                'photo_path' => $enrollment->student?->photo_path,
            ]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
