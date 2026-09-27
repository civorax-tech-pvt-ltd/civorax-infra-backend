<?php

namespace Tests\Feature;

use App\Filament\Client\Widgets\ClientProjects;
use App\Filament\Client\Widgets\ClientStats;
use App\Filament\Client\Widgets\QuotationsAwaitingResponse;
use App\Filament\Student\Widgets\MyCourses;
use App\Filament\Student\Widgets\StudentStats;
use App\Filament\Student\Widgets\UpcomingClasses;
use App\Filament\Team\Widgets\MyOpenTasks;
use App\Filament\Team\Widgets\MyProjects;
use App\Filament\Team\Widgets\MyWorkStats;
use App\Filament\Widgets\ProjectStatsOverview;
use App\Filament\Widgets\WelcomeBanner;
use App\Models\ClassSession;
use App\Models\Client;
use App\Models\ClientType;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Project;
use App\Models\ProjectType;
use App\Models\Student;
use App\Models\Task;
use App\Models\TeamMember;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DashboardsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Project $project;

    protected User $clientUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['phone' => '9800000000', 'name' => 'CivoraX Super Admin']);
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
    }

    public function test_admin_dashboard_has_no_filament_card_and_shows_the_banner_and_stats(): void
    {
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->get('/admin')->assertOk()->assertDontSee('filamentphp.com')->assertSee('CivoraX');

        Livewire::test(WelcomeBanner::class)->assertSee('CivoraX')->assertSee('+ New project');
        Livewire::test(ProjectStatsOverview::class)->assertSee('Active projects')->assertSee('Team present today');
    }

    public function test_team_dashboard_shows_the_members_own_work(): void
    {
        $user = User::factory()->create(['phone' => '9822222222']);
        $member = TeamMember::create([
            'user_id' => $user->id, 'fullname' => 'Hari Bdr', 'contact1' => '9822222222', 'marital_status' => 'Single',
            'national_id_path' => 'ids/id.jpg', 'bank_name' => 'Nabil', 'bank_account_name' => 'Hari', 'bank_account_number' => '1',
            'created_by' => $this->admin->id,
        ]);
        $role = Role::create(['name' => 'engineer']);
        $role->givePermissionTo(Permission::create(['name' => 'update_task']));
        $user->assignRole($role);
        $this->project->teamMembers()->attach($member);

        $mine = Task::create(['project_id' => $this->project->id, 'title' => 'Draw plans', 'assignee_id' => $member->id, 'status' => 'pending', 'due_at' => now()->addDay(), 'created_by' => $this->admin->id]);
        $notMine = Task::create(['project_id' => $this->project->id, 'title' => 'Site visit', 'status' => 'pending', 'created_by' => $this->admin->id]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('team'));

        $this->get('/team')->assertOk()->assertDontSee('filamentphp.com');

        Livewire::test(WelcomeBanner::class)->assertSee('Good')->assertSee('Hari')->assertSee('1 open task');
        Livewire::test(MyWorkStats::class)->assertSee('Open tasks')->assertSee('Days present');
        Livewire::test(MyProjects::class)->assertSee('Interior – Shishir Sharma');

        Livewire::test(MyOpenTasks::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$notMine])
            ->callTableAction('start', $mine);

        $this->assertSame('in_progress', $mine->refresh()->status);
    }

    public function test_client_dashboard_shows_projects_money_and_open_quotations(): void
    {
        $quotation = $this->project->quotations()->create(['status' => 'sent', 'valid_until' => now()->addWeek()]);
        $quotation->items()->create(['description' => 'Design', 'quantity' => 1, 'unit' => 'lump sum', 'rate' => 50000]);

        $this->actingAs($this->clientUser);
        Filament::setCurrentPanel(Filament::getPanel('client'));

        $this->get('/client')->assertOk()->assertDontSee('filamentphp.com');

        Livewire::test(ClientStats::class)->assertSee('Contract value')->assertSee('NPR 100,000');
        Livewire::test(ClientProjects::class)->assertSee('Interior – Shishir Sharma');
        $this->assertTrue(QuotationsAwaitingResponse::canView());
        Livewire::test(QuotationsAwaitingResponse::class)->assertCanSeeTableRecords([$quotation->refresh()]);
    }

    public function test_student_dashboard_shows_courses_classes_and_fees(): void
    {
        $user = User::factory()->create(['phone' => '9844444444']);
        $student = Student::create(['user_id' => $user->id, 'fullname' => 'Student Shisir', 'dob' => '2004-01-01', 'contact' => '9844444444', 'address' => 'Itahari']);
        $course = Course::create(['title' => 'AutoCAD Basics', 'description' => 'Learn AutoCAD', 'syllabus' => 'Drawing basics', 'type' => 'online', 'duration' => '2 months', 'fee' => 12000, 'status' => 'active', 'created_by' => $this->admin->id]);
        Enrollment::create(['course_id' => $course->id, 'student_id' => $student->id, 'enrolled_at' => today(), 'created_by' => $this->admin->id]);
        $class = ClassSession::create(['course_id' => $course->id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHours(2), 'status' => 'scheduled', 'meeting_url' => 'https://meet.example.com/abc', 'created_by' => $this->admin->id]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('student'));

        $this->get('/student')->assertOk()->assertDontSee('filamentphp.com');

        Livewire::test(StudentStats::class)->assertSee('Balance due')->assertSee('NPR 12,000');
        Livewire::test(MyCourses::class)->assertSee('AutoCAD Basics');
        Livewire::test(UpcomingClasses::class)->assertCanSeeTableRecords([$class])->assertTableActionVisible('join', $class);
    }
}
