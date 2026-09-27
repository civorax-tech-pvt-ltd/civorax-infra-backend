<?php

namespace App\Filament\Student\Widgets;

use App\Filament\Student\Resources\EnrollmentResource;
use App\Models\Enrollment;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

class MyCourses extends Widget
{
    protected static string $view = 'filament.widgets.project-cards';

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array{heading: string, empty: string, cards: Collection<int, array<string, mixed>>}
     */
    protected function getViewData(): array
    {
        return [
            'heading' => 'My courses',
            'empty' => 'You are not enrolled in any course yet.',
            'cards' => StudentStats::myEnrollments()->map(function (Enrollment $enrollment): array {
                $fee = (float) $enrollment->course?->fee;
                $paid = (float) $enrollment->coursePayments->sum('amount');

                return [
                    'title' => $enrollment->course?->title,
                    'meta' => collect([$enrollment->course?->type, $enrollment->course?->duration])->filter()->implode(' · '),
                    'status' => $fee > 0 && $paid >= $fee ? 'Paid' : 'Fee due',
                    'progress' => $fee > 0 ? (int) min(100, round($paid / $fee * 100)) : 100,
                    'footer' => 'Paid NPR '.number_format($paid).' of '.number_format($fee),
                    'url' => EnrollmentResource::getUrl('view', ['record' => $enrollment]),
                ];
            }),
        ];
    }
}
