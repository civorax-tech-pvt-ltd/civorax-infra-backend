<?php

namespace App\Notifications;

use App\Filament\Client\Resources\ProjectResource as ClientProjectResource;
use App\Filament\Resources\InquiryResource;
use App\Filament\Resources\MusterRollResource;
use App\Filament\Resources\ProjectResource;
use App\Filament\Resources\SiteReportResource;
use App\Filament\Resources\TaskResource;
use App\Filament\Student\Resources\EnrollmentResource as StudentEnrollmentResource;
use App\Models\ClassSession;
use App\Models\CoursePayment;
use App\Models\CoursePaymentSubmission;
use App\Models\Enrollment;
use App\Models\Inquiry;
use App\Models\MusterRoll;
use App\Models\Payment;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\ProjectMilestone;
use App\Models\ProjectPaymentSubmission;
use App\Models\Quotation;
use App\Models\SiteReport;
use App\Models\Task;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The wording and links of every important notification, grouped by who receives it.
 */
class Alerts
{
    /**
     * Client portal project page, optionally on one of its tabs
     * (0 milestones, 1 documents, 2 payments, 3 payment submissions, 4 quotations, 5 site diary).
     */
    protected static function clientProjectUrl(Project $project, ?int $tab = null): string
    {
        return ClientProjectResource::getUrl('view', ['record' => $project], panel: 'client')
            .($tab === null ? '' : "?activeRelationManager={$tab}");
    }

    protected static function money(float|string|null $amount): string
    {
        return 'NPR '.number_format((float) $amount, 2);
    }

    // ── Client ────────────────────────────────────────────────────────────────

    public static function quotationSent(Quotation $quotation): void
    {
        Alert::send(
            $quotation->project->client?->user,
            "New quotation for {$quotation->project->title}",
            "{$quotation->label()} is ready. Review it, then accept or request changes.",
            static::clientProjectUrl($quotation->project, 4),
            'heroicon-o-document-text',
            'info',
        );
    }

    public static function paymentReceived(Payment $payment): void
    {
        $project = $payment->project;

        Alert::send(
            $project->client?->user,
            'Payment received: '.static::money($payment->amount),
            "{$project->title}. Thank you! Remaining balance: ".static::money($project->balanceDue()).'.',
            static::clientProjectUrl($project, 2),
            'heroicon-o-check-circle',
            'success',
        );
    }

    public static function paymentSubmissionRejected(ProjectPaymentSubmission $submission): void
    {
        Alert::send(
            $submission->submitter ?? $submission->project->client?->user,
            'Payment could not be verified',
            static::money($submission->amount)." (ref {$submission->transaction_reference}): {$submission->review_note}",
            static::clientProjectUrl($submission->project, 3),
            'heroicon-o-x-circle',
            'danger',
        );
    }

    public static function milestoneCompleted(ProjectMilestone $milestone): void
    {
        $due = $milestone->amountLeft();

        Alert::send(
            $milestone->project->client?->user,
            "Milestone completed: {$milestone->title}",
            $milestone->project->title.'. '.($due > 0 ? static::money($due).' is now due.' : 'Great progress!'),
            static::clientProjectUrl($milestone->project, 0),
            'heroicon-o-flag',
            'success',
        );
    }

    public static function documentShared(ProjectDocument $document): void
    {
        Alert::send(
            $document->project?->client?->user,
            "New document: {$document->title}",
            "Shared on {$document->project?->title}.",
            $document->project ? static::clientProjectUrl($document->project, 1) : null,
            'heroicon-o-paper-clip',
            'info',
        );
    }

    // ── Team ──────────────────────────────────────────────────────────────────

    public static function taskAssigned(Task $task): void
    {
        Alert::send(
            $task->assignee?->user,
            "New task: {$task->title}",
            collect([$task->project?->title, $task->due_at ? 'due '.$task->due_at->timezone(config('app.business_timezone'))->format('M j, g:i A') : null])->filter()->implode(' · '),
            TaskResource::getUrl(panel: 'team'),
            'heroicon-o-clipboard-document-list',
            'primary',
        );
    }

    /**
     * @param  list<int|string>  $teamMemberIds
     */
    public static function addedAsHelper(Task $task, array $teamMemberIds): void
    {
        Alert::send(
            static::usersOf($teamMemberIds),
            "You are helping on: {$task->title}",
            collect([$task->project?->title, $task->assignee ? 'with '.$task->assignee->fullname : null])->filter()->implode(' · '),
            TaskResource::getUrl(panel: 'team'),
            'heroicon-o-user-plus',
            'primary',
        );
    }

    /**
     * @param  list<int|string>  $teamMemberIds
     */
    public static function addedToProject(Project $project, array $teamMemberIds): void
    {
        Alert::send(
            static::usersOf($teamMemberIds),
            "Added to project: {$project->title}",
            'You can now see it and its tasks in the team panel.',
            ProjectResource::getUrl(panel: 'team'),
            'heroicon-o-building-office-2',
            'primary',
        );
    }

    /**
     * @param  Collection<int, Task>  $tasks
     */
    public static function dailyTaskReminder(TeamMember $teamMember, Collection $tasks): void
    {
        $overdue = $tasks->filter->isOverdue()->count();
        $dueToday = $tasks->count() - $overdue;

        Alert::send(
            $teamMember->user,
            collect([$dueToday ? "{$dueToday} ".str('task')->plural($dueToday).' due today' : null, $overdue ? "{$overdue} overdue" : null])->filter()->implode(', '),
            $tasks->take(3)->pluck('title')->implode(' · ').($tasks->count() > 3 ? ' …' : ''),
            TaskResource::getUrl(panel: 'team'),
            'heroicon-o-bell-alert',
            $overdue ? 'danger' : 'warning',
        );
    }

    // ── Admin ─────────────────────────────────────────────────────────────────

    public static function milestoneReadyToComplete(ProjectMilestone $milestone): void
    {
        NotifyAdmins::send(
            "Ready to mark complete: {$milestone->title}",
            "All tasks are done on {$milestone->project->title}. Completing it makes its billing due.",
            ProjectResource::getUrl('edit', ['record' => $milestone->project_id], panel: 'admin'),
            'heroicon-o-flag',
            'success',
        );
    }

    public static function newInquiry(Inquiry $inquiry): void
    {
        NotifyAdmins::send(
            "New inquiry from {$inquiry->fullname}",
            str($inquiry->message)->limit(140)->toString(),
            InquiryResource::getUrl(panel: 'admin'),
            'heroicon-o-inbox-arrow-down',
            'info',
        );
    }

    // ── Site ──────────────────────────────────────────────────────────────────

    public static function musterRollSubmitted(MusterRoll $roll): void
    {
        static::notifySiteApprovers(
            "Muster roll to approve: {$roll->label()}",
            "{$roll->project->title} · {$roll->lines()->count()} labourers · ".static::money($roll->totalWage()),
            fn (string $panel): string => MusterRollResource::getUrl('view', ['record' => $roll], panel: $panel),
            'heroicon-o-clipboard-document-check',
        );
    }

    public static function musterRollReviewed(MusterRoll $roll): void
    {
        $approved = $roll->status === 'approved';

        Alert::send(
            $roll->preparer,
            ($approved ? 'Muster roll approved: ' : 'Muster roll returned: ').$roll->label(),
            $roll->project->title.($roll->review_note ? " · {$roll->review_note}" : ''),
            MusterRollResource::getUrl('view', ['record' => $roll], panel: 'team'),
            $approved ? 'heroicon-o-check-circle' : 'heroicon-o-arrow-uturn-left',
            $approved ? 'success' : 'warning',
        );
    }

    public static function siteReportSubmitted(SiteReport $report): void
    {
        static::notifySiteApprovers(
            'Site report to review: '.$report->date->format('M j'),
            "{$report->project->title} · ".str(strip_tags($report->work_done))->limit(100),
            fn (string $panel): string => SiteReportResource::getUrl('view', ['record' => $report], panel: $panel),
            'heroicon-o-document-magnifying-glass',
        );
    }

    public static function siteReportReviewed(SiteReport $report): void
    {
        $approved = $report->status === 'approved';

        Alert::send(
            $report->submitter,
            ($approved ? 'Site report approved: ' : 'Site report returned: ').$report->date->format('M j'),
            $report->project->title.($report->review_note ? " · {$report->review_note}" : ''),
            SiteReportResource::getUrl('view', ['record' => $report], panel: 'team'),
            $approved ? 'heroicon-o-check-circle' : 'heroicon-o-arrow-uturn-left',
            $approved ? 'success' : 'warning',
        );
    }

    public static function siteReportPublished(SiteReport $report): void
    {
        Alert::send(
            $report->project->client?->user,
            'Site update: '.$report->date->format('l, M j'),
            "{$report->project->title} · ".str(strip_tags($report->work_done))->limit(120),
            static::clientProjectUrl($report->project, 5),
            'heroicon-o-camera',
            'info',
        );
    }

    /**
     * @param  Collection<int, TeamMember>  $teamMembers
     */
    public static function siteReportReminder(Project $project, Collection $teamMembers): void
    {
        Alert::send(
            $teamMembers->pluck('user'),
            "Today's site report is missing",
            "{$project->title}. Please mark labour attendance and submit the daily report before you leave.",
            SiteReportResource::getUrl('create', panel: 'team'),
            'heroicon-o-clock',
            'warning',
        );
    }

    /**
     * Super admins get admin panel links; other approvers (roles granted the power) get team panel links.
     *
     * @param  \Closure(string): string  $url
     */
    protected static function notifySiteApprovers(string $title, string $body, \Closure $url, string $icon): void
    {
        [$admins, $others] = User::withSitePower('approve_site_records')->partition(fn (User $user): bool => $user->hasRole('super_admin'));

        Alert::send($admins, $title, $body, $url('admin'), $icon, 'warning');
        Alert::send($others, $title, $body, $url('team'), $icon, 'warning');
    }

    // ── Student ───────────────────────────────────────────────────────────────

    public static function coursePaymentVerified(CoursePayment $payment): void
    {
        Alert::send(
            $payment->enrollment?->student?->user,
            'Payment verified: '.static::money($payment->amount),
            "{$payment->enrollment?->course?->title}. Thank you!",
            static::enrollmentUrl($payment->enrollment),
            'heroicon-o-check-circle',
            'success',
        );
    }

    public static function coursePaymentRejected(CoursePaymentSubmission $submission): void
    {
        Alert::send(
            $submission->enrollment?->student?->user,
            'Payment could not be verified',
            static::money($submission->amount)." (ref {$submission->transaction_reference}): {$submission->review_note}",
            static::enrollmentUrl($submission->enrollment),
            'heroicon-o-x-circle',
            'danger',
        );
    }

    public static function classScheduled(ClassSession $session): void
    {
        $enrollments = Enrollment::query()->where('course_id', $session->course_id)->with('student.user')->get();

        foreach ($enrollments as $enrollment) {
            Alert::send(
                $enrollment->student?->user,
                "New class: {$session->course?->title}",
                $session->starts_at->timezone(config('app.business_timezone'))->format('l, M j · g:i A'),
                static::enrollmentUrl($enrollment),
                'heroicon-o-video-camera',
                'info',
            );
        }
    }

    protected static function enrollmentUrl(?Enrollment $enrollment): ?string
    {
        return $enrollment ? StudentEnrollmentResource::getUrl('view', ['record' => $enrollment], panel: 'student') : null;
    }

    /**
     * @param  list<int|string>  $teamMemberIds
     * @return Collection<int, User>
     */
    protected static function usersOf(array $teamMemberIds): Collection
    {
        return TeamMember::query()->whereKey($teamMemberIds)->with('user')->get()->pluck('user')->filter();
    }
}
