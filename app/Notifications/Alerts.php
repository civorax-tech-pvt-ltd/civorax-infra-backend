<?php

namespace App\Notifications;

use App\Filament\Client\Resources\ProjectResource as ClientProjectResource;
use App\Filament\Resources\BlogPostResource;
use App\Filament\Resources\BoqMeasurementResource;
use App\Filament\Resources\EquipmentEntryResource;
use App\Filament\Resources\InquiryResource;
use App\Filament\Resources\MusterRollResource;
use App\Filament\Resources\PettyCashClaimResource;
use App\Filament\Resources\PortfolioProjectResource;
use App\Filament\Resources\ProjectResource;
use App\Filament\Resources\PurchaseBillResource;
use App\Filament\Resources\SiteReportResource;
use App\Filament\Resources\TaskResource;
use App\Filament\Resources\VariationResource;
use App\Filament\Resources\WorkOrderResource;
use App\Filament\Student\Resources\EnrollmentResource as StudentEnrollmentResource;
use App\Models\BlogPost;
use App\Models\BoqMeasurement;
use App\Models\Certificate;
use App\Models\ClassSession;
use App\Models\CoursePayment;
use App\Models\CoursePaymentSubmission;
use App\Models\Enrollment;
use App\Models\EquipmentEntry;
use App\Models\Inquiry;
use App\Models\MusterRoll;
use App\Models\Payment;
use App\Models\PettyCashClaim;
use App\Models\PortfolioProject;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\ProjectMilestone;
use App\Models\ProjectPaymentSubmission;
use App\Models\PurchaseBill;
use App\Models\Quotation;
use App\Models\SiteReport;
use App\Models\Task;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\Variation;
use App\Models\WorkOrder;
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

    public static function purchaseBillSubmitted(PurchaseBill $bill): void
    {
        static::notifySiteApprovers(
            ($bill->isFlagged() ? 'Bill to review (not billed to the company): ' : 'Bill to approve: ').static::money($bill->total_amount),
            "{$bill->project->title} · {$bill->vendor?->name} · bill {$bill->bill_no}",
            fn (string $panel): string => PurchaseBillResource::getUrl(panel: $panel),
            'heroicon-o-receipt-percent',
            'approve_purchase_bills',
        );
    }

    public static function purchaseBillReviewed(PurchaseBill $bill): void
    {
        $approved = $bill->status === 'approved';

        Alert::send(
            $bill->enteredBy,
            ($approved ? 'Bill approved: ' : 'Bill rejected: ')."{$bill->vendor?->name} {$bill->bill_no}",
            $bill->project->title.($bill->review_note ? " · {$bill->review_note}" : ''),
            PurchaseBillResource::getUrl(panel: 'team'),
            $approved ? 'heroicon-o-check-circle' : 'heroicon-o-x-circle',
            $approved ? 'success' : 'danger',
        );
    }

    public static function variationSubmitted(Variation $variation): void
    {
        static::notifySiteApprovers(
            'Extra work to approve: '.static::money($variation->amount),
            "{$variation->project->title} · {$variation->title}",
            fn (string $panel): string => VariationResource::getUrl(panel: $panel),
            'heroicon-o-plus-circle',
            'approve_variations',
        );
    }

    public static function variationApproved(Variation $variation): void
    {
        Alert::send(
            $variation->enteredBy,
            "Extra work approved: {$variation->title}",
            "{$variation->project->title} · contract value is now ".static::money($variation->project->contractValue()),
            VariationResource::getUrl(panel: 'team'),
            'heroicon-o-check-circle',
            'success',
        );

        Alert::send(
            $variation->project->client?->user,
            "Extra work added: {$variation->title}",
            static::money($variation->amount)." added to {$variation->project->title}. New total: ".static::money($variation->project->contractValue()).'.',
            static::clientProjectUrl($variation->project, 2),
            'heroicon-o-plus-circle',
            'info',
        );
    }

    public static function workOrderSubmitted(WorkOrder $order): void
    {
        static::notifySiteApprovers(
            'Work order to approve: '.static::money($order->agreed_amount),
            "{$order->project->title} · {$order->vendor?->name} · {$order->scope}",
            fn (string $panel): string => WorkOrderResource::getUrl(panel: $panel),
            'heroicon-o-briefcase',
            'approve_work_orders',
        );
    }

    public static function workOrderReviewed(WorkOrder $order): void
    {
        $approved = $order->status === 'approved';

        Alert::send(
            $order->enteredBy,
            ($approved ? 'Work order approved: ' : 'Work order rejected: ').$order->number,
            "{$order->vendor?->name} · {$order->scope}".($order->review_note ? " · {$order->review_note}" : ''),
            WorkOrderResource::getUrl(panel: 'team'),
            $approved ? 'heroicon-o-check-circle' : 'heroicon-o-x-circle',
            $approved ? 'success' : 'danger',
        );
    }

    public static function equipmentSubmitted(EquipmentEntry $entry): void
    {
        static::notifySiteApprovers(
            (EquipmentEntry::KINDS[$entry->kind] ?? 'Equipment').' to approve: '.static::money($entry->amount),
            "{$entry->project->title} · {$entry->description}",
            fn (string $panel): string => EquipmentEntryResource::getUrl(panel: $panel),
            'heroicon-o-truck',
            'approve_equipment',
        );
    }

    public static function pettyCashSubmitted(PettyCashClaim $claim): void
    {
        static::notifySiteApprovers(
            'Petty-cash claim: '.static::money($claim->amount),
            "{$claim->project->title} · {$claim->description} · by {$claim->claimant?->name}",
            fn (string $panel): string => PettyCashClaimResource::getUrl(panel: $panel),
            'heroicon-o-banknotes',
            'approve_petty_cash',
        );
    }

    public static function pettyCashReviewed(PettyCashClaim $claim): void
    {
        $approved = $claim->status === 'approved';

        Alert::send(
            $claim->claimant,
            ($approved ? 'Claim approved: ' : 'Claim rejected: ').static::money($claim->amount),
            $claim->description.($claim->review_note ? " · {$claim->review_note}" : ''),
            PettyCashClaimResource::getUrl(panel: 'team'),
            $approved ? 'heroicon-o-check-circle' : 'heroicon-o-x-circle',
            $approved ? 'success' : 'danger',
        );
    }

    public static function measurementSubmitted(BoqMeasurement $measurement): void
    {
        $item = $measurement->boqItem;

        static::notifySiteApprovers(
            "Measurement to approve: {$item->description}",
            "{$item->project->title} · {$measurement->executed_quantity} of {$item->quantity} {$item->unit} done to date",
            fn (string $panel): string => BoqMeasurementResource::getUrl(panel: $panel),
            'heroicon-o-calculator',
            'approve_boq_measurements',
        );
    }

    public static function measurementReviewed(BoqMeasurement $measurement): void
    {
        $approved = $measurement->status === 'approved';

        Alert::send(
            $measurement->enteredBy,
            ($approved ? 'Measurement approved: ' : 'Measurement rejected: ').$measurement->boqItem->description,
            $measurement->boqItem->project->title.($measurement->review_note ? " · {$measurement->review_note}" : ''),
            BoqMeasurementResource::getUrl(panel: 'team'),
            $approved ? 'heroicon-o-check-circle' : 'heroicon-o-x-circle',
            $approved ? 'success' : 'danger',
        );
    }

    public static function blogPostSubmitted(BlogPost $post): void
    {
        static::notifySiteApprovers(
            "Blog post to review: {$post->title}",
            'Written by '.($post->author_name ?: $post->author?->name ?: 'a team member'),
            fn (string $panel): string => BlogPostResource::getUrl('edit', ['record' => $post], panel: $panel),
            'heroicon-o-newspaper',
            'approve_blog_posts',
        );
    }

    public static function blogPostReviewed(BlogPost $post): void
    {
        $author = $post->author;
        $published = $post->status === 'published';

        if ($author === null) {
            return;
        }

        $live = $post->published_at === null || $post->published_at->isPast();

        Alert::send(
            $author,
            ($published ? ($live ? 'Blog post published: ' : 'Blog post scheduled: ') : 'Changes requested: ').$post->title,
            $published
                ? ($live ? 'It is now live on the website.' : 'It goes live on '.$post->published_at->format('M j, Y g:i A').'.')
                : (string) $post->review_note,
            BlogPostResource::getUrl('edit', ['record' => $post], panel: $author->hasRole('super_admin') ? 'admin' : 'team'),
            $published ? 'heroicon-o-check-circle' : 'heroicon-o-pencil-square',
            $published ? 'success' : 'warning',
        );
    }

    public static function portfolioProjectSubmitted(PortfolioProject $project): void
    {
        static::notifySiteApprovers(
            "Our Work project to review: {$project->title}",
            'Submitted by '.($project->submitter?->name ?? 'a team member'),
            fn (string $panel): string => PortfolioProjectResource::getUrl('edit', ['record' => $project], panel: $panel),
            'heroicon-o-photo',
            'approve_portfolio_projects',
        );
    }

    public static function portfolioProjectReviewed(PortfolioProject $project): void
    {
        $submitter = $project->submitter;

        if ($submitter === null || (int) $submitter->getKey() === (int) $project->reviewed_by) {
            return;
        }

        $published = $project->is_published;
        $live = $project->published_at === null || $project->published_at->isPast();

        Alert::send(
            $submitter,
            ($published ? ($live ? 'Project published: ' : 'Project scheduled: ') : 'Changes requested: ').$project->title,
            $published
                ? ($live ? 'It is now live on Our Work.' : 'It goes live on '.$project->published_at->format('M j, Y g:i A').'.')
                : (string) $project->review_note,
            PortfolioProjectResource::getUrl('edit', ['record' => $project], panel: $submitter->hasRole('super_admin') ? 'admin' : 'team'),
            $published ? 'heroicon-o-check-circle' : 'heroicon-o-pencil-square',
            $published ? 'success' : 'warning',
        );
    }

    /**
     * Super admins get admin panel links; other approvers (roles granted the power) get team panel links.
     *
     * @param  \Closure(string): string  $url
     */
    protected static function notifySiteApprovers(string $title, string $body, \Closure $url, string $icon, string $permission = 'approve_site_records'): void
    {
        [$admins, $others] = User::withSitePower($permission)->partition(fn (User $user): bool => $user->hasRole('super_admin'));

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

    public static function certificateIssued(Certificate $certificate): void
    {
        Alert::send(
            $certificate->enrollment?->student?->user,
            'Your certificate is ready',
            "{$certificate->course_title} · {$certificate->number}. Congratulations!",
            static::enrollmentUrl($certificate->enrollment),
            'heroicon-o-academic-cap',
            'success',
        );
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
