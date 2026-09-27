<?php

namespace Tests\Feature;

use App\Livewire\DesktopAlerts;
use App\Models\ClassSession;
use App\Models\Client;
use App\Models\ClientType;
use App\Models\Course;
use App\Models\CoursePaymentSubmission;
use App\Models\Enrollment;
use App\Models\Inquiry;
use App\Models\InquiryType;
use App\Models\Payment;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\ProjectType;
use App\Models\Student;
use App\Models\Task;
use App\Models\TeamMember;
use App\Models\User;
use App\Notifications\Alert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $clientUser;

    protected User $memberUser;

    protected TeamMember $member;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['phone' => '9800000000']);
        $this->admin->assignRole(Role::create(['name' => 'super_admin']));

        $this->clientUser = User::factory()->create(['phone' => '9811111111']);
        $client = Client::create([
            'user_id' => $this->clientUser->id,
            'contact_person' => 'Shishir Sharma',
            'client_type_id' => ClientType::create(['name' => 'Individual', 'slug' => 'individual'])->id,
            'contact' => '9811111111',
            'address' => 'Belbari',
        ]);

        $this->project = Project::create([
            'client_id' => $client->id,
            'project_type_id' => ProjectType::create(['name' => 'Interior', 'slug' => 'interior', 'is_active' => true])->id,
            'title' => 'Interior – Shishir Sharma',
            'description' => 'Interior work',
            'site_address' => 'Belbari',
            'status' => 'designing',
            'fee' => 100000,
            'created_by' => $this->admin->id,
        ]);

        $this->memberUser = User::factory()->create(['phone' => '9822222222']);
        $this->member = TeamMember::create([
            'user_id' => $this->memberUser->id, 'fullname' => 'Hari Bdr', 'contact1' => '9822222222', 'marital_status' => 'Single',
            'national_id_path' => 'ids/id.jpg', 'bank_name' => 'Nabil', 'bank_account_name' => 'Hari', 'bank_account_number' => '1',
            'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin);
    }

    /**
     * The notification with this title (notifications created within one second have no reliable order).
     */
    protected function notificationTitled(User $user, string $title): ?DatabaseNotification
    {
        return $user->notifications()->get()->first(fn ($notification): bool => $notification->data['title'] === $title);
    }

    protected function assertNotified(User $user, string $title): void
    {
        $this->assertNotNull($this->notificationTitled($user, $title), "Expected a \"{$title}\" notification.");
    }

    public function test_clients_hear_about_quotations_payments_milestones_and_documents(): void
    {
        $quotation = $this->project->quotations()->create(['status' => 'draft']);
        $this->assertSame(0, $this->clientUser->notifications()->count());

        $quotation->update(['status' => 'sent']);
        $this->assertNotified($this->clientUser, 'New quotation for Interior – Shishir Sharma');

        Payment::create(['project_id' => $this->project->id, 'amount' => 10000, 'received_at' => today(), 'recorded_by' => $this->admin->id]);
        $this->assertNotified($this->clientUser, 'Payment received: NPR 10,000.00');

        $milestone = $this->project->milestones()->create(['title' => 'Site Survey', 'sequence' => 1, 'billing_percent' => 20, 'status' => 'pending']);
        $milestone->update(['status' => 'completed']);
        $this->assertStringContainsString('NPR 10,000.00 is now due', $this->notificationTitled($this->clientUser, 'Milestone completed: Site Survey')->data['body']);

        ProjectDocument::create(['project_id' => $this->project->id, 'title' => '3D renders', 'type' => 'drawing', 'file_path' => 'x.pdf', 'uploaded_by' => $this->admin->id]);
        $this->assertStringContainsString('/client/projects/', $this->notificationTitled($this->clientUser, 'New document: 3D renders')->data['actions'][0]['url']);
    }

    public function test_clients_hear_when_a_submitted_payment_is_rejected(): void
    {
        $submission = $this->project->paymentSubmissions()->create(['amount' => 5000, 'transaction_reference' => 'WRONG', 'submitted_by' => $this->clientUser->id]);

        $submission->reject($this->admin, 'Not in bank statement');

        $this->assertStringContainsString('Not in bank statement', $this->notificationTitled($this->clientUser, 'Payment could not be verified')->data['body']);
    }

    public function test_team_members_hear_about_assignments_and_admins_about_ready_milestones(): void
    {
        $milestone = $this->project->milestones()->create(['title' => 'Design', 'sequence' => 1, 'status' => 'pending']);

        $task = Task::create(['project_id' => $this->project->id, 'milestone_id' => $milestone->id, 'title' => 'Draw plans', 'assignee_id' => $this->member->id, 'status' => 'pending', 'created_by' => $this->admin->id]);
        $this->assertNotified($this->memberUser, 'New task: Draw plans');

        // The team member finishing their own task notifies admins, not themselves.
        $this->actingAs($this->memberUser);
        $task->update(['status' => 'completed']);

        $this->assertSame(1, $this->memberUser->notifications()->count());
        $this->assertNotified($this->admin, 'Ready to mark complete: Design');
    }

    public function test_nobody_is_notified_about_their_own_action(): void
    {
        Alert::send($this->admin, 'Self', 'Should be skipped', null, 'heroicon-o-bell');

        $this->assertSame(0, $this->admin->notifications()->count());
    }

    public function test_admins_hear_about_new_inquiries_from_others(): void
    {
        $this->actingAs($this->memberUser);

        Inquiry::create([
            'fullname' => 'Ram Karki', 'contact' => '9855555555', 'inquiry_type_id' => InquiryType::create(['label' => 'House design', 'slug' => 'house-design'])->id,
            'contact_channel' => 'phone', 'message' => 'Need a 2-storey house design.', 'status' => 'new',
        ]);

        $this->assertNotified($this->admin, 'New inquiry from Ram Karki');
    }

    public function test_students_hear_about_payment_reviews_and_new_classes(): void
    {
        $studentUser = User::factory()->create(['phone' => '9844444444']);
        $student = Student::create(['user_id' => $studentUser->id, 'fullname' => 'Student Shisir', 'dob' => '2004-01-01', 'contact' => '9844444444', 'address' => 'Itahari']);
        $course = Course::create(['title' => 'AutoCAD Basics', 'description' => 'x', 'syllabus' => 'x', 'type' => 'online', 'duration' => '2 months', 'fee' => 12000, 'status' => 'active', 'created_by' => $this->admin->id]);
        $enrollment = Enrollment::create(['course_id' => $course->id, 'student_id' => $student->id, 'enrolled_at' => today(), 'created_by' => $this->admin->id]);

        $submission = CoursePaymentSubmission::create(['enrollment_id' => $enrollment->id, 'amount' => 6000, 'transaction_reference' => 'ESEWA-1']);
        $submission->approve($this->admin);
        $this->assertNotified($studentUser, 'Payment verified: NPR 6,000.00');

        ClassSession::create(['course_id' => $course->id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(), 'status' => 'scheduled', 'created_by' => $this->admin->id]);
        $this->assertNotified($studentUser, 'New class: AutoCAD Basics');
    }

    public function test_the_daily_reminder_lists_tasks_due_today_and_overdue(): void
    {
        Carbon::setTestNow('2026-09-28 02:15:00'); // 8:00 AM in Nepal

        Task::create(['project_id' => $this->project->id, 'title' => 'Late drawing', 'assignee_id' => $this->member->id, 'status' => 'in_progress', 'due_at' => now()->subDay(), 'created_by' => $this->admin->id]);
        Task::create(['project_id' => $this->project->id, 'title' => 'Site visit', 'assignee_id' => $this->member->id, 'status' => 'pending', 'due_at' => now()->addHours(6), 'created_by' => $this->admin->id]);
        Task::create(['project_id' => $this->project->id, 'title' => 'Next week', 'assignee_id' => $this->member->id, 'status' => 'pending', 'due_at' => now()->addWeek(), 'created_by' => $this->admin->id]);
        $this->memberUser->notifications()->delete();

        $this->artisan('tasks:send-reminders')->assertSuccessful();

        $notification = $this->memberUser->notifications()->sole();
        $this->assertSame('1 task due today, 1 overdue', $notification->data['title']);
        $this->assertSame('Late drawing · Site visit', $notification->data['body']);
    }

    public function test_desktop_alerts_only_return_notifications_newer_than_the_last_check(): void
    {
        $this->actingAs($this->clientUser);
        $component = Livewire::test(DesktopAlerts::class);

        $first = $component->instance()->check(null);
        $this->assertSame([], $first['alerts']);

        Carbon::setTestNow(now()->addMinute());
        $this->actingAs($this->admin);
        Payment::create(['project_id' => $this->project->id, 'amount' => 2500, 'received_at' => today(), 'recorded_by' => $this->admin->id]);

        $this->actingAs($this->clientUser);
        $next = $component->instance()->check($first['now']);

        $this->assertCount(1, $next['alerts']);
        $this->assertSame('Payment received: NPR 2,500.00', $next['alerts'][0]['title']);
        $this->assertStringContainsString('/client/projects/', $next['alerts'][0]['url']);
        $this->assertSame([], $component->instance()->check($next['now'])['alerts']);
    }
}
