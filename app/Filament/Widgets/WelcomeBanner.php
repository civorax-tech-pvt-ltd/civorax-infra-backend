<?php

namespace App\Filament\Widgets;

use App\Filament\Client\Resources\ProjectResource as ClientProjectResource;
use App\Filament\Resources\AttendanceResource;
use App\Filament\Resources\ClientResource;
use App\Filament\Resources\PaymentResource;
use App\Filament\Resources\ProjectPaymentSubmissionResource;
use App\Filament\Resources\ProjectResource;
use App\Filament\Resources\TaskResource;
use App\Filament\Student\Resources\EnrollmentResource as StudentEnrollmentResource;
use App\Models\ProjectPaymentSubmission;
use App\Models\Task;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

/**
 * Greeting + quick actions at the top of every panel's dashboard.
 */
class WelcomeBanner extends Widget
{
    protected static string $view = 'filament.widgets.welcome-banner';

    protected static ?int $sort = -10;

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array{greeting: string, date: string, subtitle: string, actions: list<array{label: string, url: string, badge?: int}>}
     */
    protected function getViewData(): array
    {
        $user = auth()->user();
        $now = now(config('app.business_timezone'));
        $firstName = str($user?->teamMember?->fullname ?? $user?->client?->contact_person ?? $user?->student?->fullname ?? $user?->name ?? '')->before(' ');

        $greeting = match (true) {
            $now->hour < 12 => 'Good morning',
            $now->hour < 17 => 'Good afternoon',
            default => 'Good evening',
        };

        [$subtitle, $actions] = match (Filament::getCurrentPanel()?->getId()) {
            'admin' => $this->adminContent(),
            'team' => $this->teamContent(),
            'client' => ['Follow your projects, review quotations and make payments in one place.', [
                ['label' => 'My projects', 'url' => ClientProjectResource::getUrl()],
            ]],
            'student' => ['Your courses, class schedule and fee payments.', [
                ['label' => 'My courses', 'url' => StudentEnrollmentResource::getUrl()],
            ]],
            default => ['', []],
        };

        return [
            'greeting' => trim("{$greeting}, {$firstName}", ', '),
            'date' => $now->format('l, F j, Y'),
            'subtitle' => $subtitle,
            'actions' => $actions,
        ];
    }

    /**
     * @return array{0: string, 1: list<array{label: string, url: string, badge?: int}>}
     */
    protected function adminContent(): array
    {
        $pendingPayments = ProjectPaymentSubmission::query()->where('status', 'pending')->count();

        return ['Here is what is happening across CivoraX today.', array_values(array_filter([
            ['label' => '+ New project', 'url' => ProjectResource::getUrl('create')],
            ['label' => '+ Record payment', 'url' => PaymentResource::getUrl('create')],
            ['label' => '+ Add client', 'url' => ClientResource::getUrl('create')],
            ['label' => 'Payments to verify', 'url' => ProjectPaymentSubmissionResource::getUrl(), 'badge' => $pendingPayments],
            ['label' => 'Attendance', 'url' => AttendanceResource::getUrl()],
        ]))];
    }

    /**
     * @return array{0: string, 1: list<array{label: string, url: string, badge?: int}>}
     */
    protected function teamContent(): array
    {
        $teamMember = auth()->user()?->teamMember;
        $open = $teamMember ? Task::query()->involving($teamMember)->where('status', '!=', 'completed')->count() : 0;
        $dueToday = $teamMember ? Task::query()->involving($teamMember)->where('status', '!=', 'completed')
            ->whereBetween('due_at', [now()->startOfDay(), now()->endOfDay()])->count() : 0;

        return ["You have {$open} open ".str('task')->plural($open).($dueToday ? " · {$dueToday} due today" : '').'.', [
            ['label' => 'My tasks', 'url' => TaskResource::getUrl(), 'badge' => $open],
            ['label' => 'My projects', 'url' => ProjectResource::getUrl()],
            ['label' => 'My attendance', 'url' => AttendanceResource::getUrl()],
        ]];
    }
}
