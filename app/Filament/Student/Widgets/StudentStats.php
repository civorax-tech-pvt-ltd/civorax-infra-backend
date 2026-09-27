<?php

namespace App\Filament\Student\Widgets;

use App\Models\ClassSession;
use App\Models\CoursePaymentSubmission;
use App\Models\Enrollment;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class StudentStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getColumns(): int
    {
        return 4;
    }

    /**
     * @return Collection<int, Enrollment>
     */
    public static function myEnrollments(): Collection
    {
        return Enrollment::query()
            ->whereHas('student', fn (Builder $query) => $query->where('user_id', auth()->id()))
            ->with(['course', 'coursePayments'])
            ->get();
    }

    protected function getStats(): array
    {
        $enrollments = static::myEnrollments();
        $fees = (float) $enrollments->sum(fn (Enrollment $enrollment): float => (float) $enrollment->course?->fee);
        $paid = (float) $enrollments->sum(fn (Enrollment $enrollment): float => (float) $enrollment->coursePayments->sum('amount'));
        $pending = (float) CoursePaymentSubmission::query()->whereIn('enrollment_id', $enrollments->modelKeys())->where('status', 'pending')->sum('amount');
        $nextClass = ClassSession::query()
            ->whereIn('course_id', $enrollments->pluck('course_id'))
            ->where('starts_at', '>=', now())
            ->where('status', '!=', 'cancelled')
            ->orderBy('starts_at')
            ->first();

        return [
            Stat::make('My courses', $enrollments->count())
                ->description('Enrolled')
                ->descriptionIcon('heroicon-m-academic-cap')
                ->color('primary'),
            Stat::make('Next class', $nextClass?->starts_at->timezone(config('app.business_timezone'))->format('M j, g:i A') ?? '—')
                ->description($nextClass ? $nextClass->starts_at->diffForHumans() : 'No class scheduled')
                ->descriptionIcon('heroicon-m-video-camera')
                ->color('info'),
            Stat::make('Paid', 'NPR '.number_format($paid))
                ->description($pending > 0 ? 'NPR '.number_format($pending).' awaiting verification' : 'Of NPR '.number_format($fees).' total fees')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success'),
            Stat::make('Balance due', 'NPR '.number_format(max(0, $fees - $paid)))
                ->description($fees - $paid > 0 ? 'Pay from your course page' : 'Fully paid 🎉')
                ->descriptionIcon($fees - $paid > 0 ? 'heroicon-m-exclamation-circle' : 'heroicon-m-face-smile')
                ->color($fees - $paid > 0 ? 'danger' : 'success'),
        ];
    }
}
