<?php

namespace Tests\Feature;

use App\Filament\Resources\ProjectResource\Pages\CreateProject;
use App\Filament\Resources\ProjectResource\Pages\EditProject;
use App\Filament\Resources\ProjectResource\Pages\ListProjects;
use App\Filament\Resources\ProjectResource\RelationManagers\QuotationsRelationManager;
use App\Filament\Resources\TaskResource\Pages\EditTask;
use App\Filament\Resources\TaskResource\Pages\ListTasks;
use App\Filament\Widgets\BehindScheduleProjects;
use App\Filament\Widgets\OverdueTasks;
use App\Filament\Widgets\ProjectStatsOverview;
use App\Models\Client;
use App\Models\ClientType;
use App\Models\Payment;
use App\Models\Project;
use App\Models\ProjectType;
use App\Models\Task;
use App\Models\TeamMember;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class QuotationsTeamPanelAndDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Client $client;

    protected ProjectType $projectType;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $superAdmin = Role::create(['name' => 'super_admin']);

        foreach (['project', 'project::milestone', 'task'] as $resource) {
            foreach (['view_any', 'view', 'create', 'update', 'delete'] as $action) {
                $superAdmin->givePermissionTo(Permission::firstOrCreate(['name' => "{$action}_{$resource}"]));
            }
        }

        $this->admin = User::factory()->create(['phone' => '9800000000']);
        $this->admin->assignRole($superAdmin);
        $this->actingAs($this->admin);

        $this->client = Client::create([
            'user_id' => User::factory()->create(['phone' => '9811111111'])->id,
            'contact_person' => 'Tester Client',
            'client_type_id' => ClientType::create(['name' => 'Individual', 'slug' => 'individual'])->id,
            'contact' => '9811111111',
            'address' => 'Butwal',
        ]);

        $this->projectType = ProjectType::create(['name' => 'Residential Design', 'slug' => 'residential-design', 'is_active' => true]);
    }

    public function test_a_project_can_start_without_a_fee_but_needs_one_once_design_begins(): void
    {
        $data = [
            'client_id' => $this->client->id,
            'project_type_id' => $this->projectType->id,
            'title' => 'House Design',
            'description' => 'Two storey house',
            'site_address' => 'Ward 5',
        ];

        Livewire::test(CreateProject::class)
            ->fillForm([...$data, 'status' => 'designing'])
            ->call('create')
            ->assertHasFormErrors(['fee' => 'required']);

        Livewire::test(CreateProject::class)
            ->fillForm([...$data, 'status' => 'inquiry'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertNull(Project::firstOrFail()->fee);
    }

    public function test_the_title_is_suggested_and_the_scope_of_work_keeps_its_formatting(): void
    {
        Livewire::test(CreateProject::class)
            ->fillForm([
                'client_id' => $this->client->id,
                'project_type_id' => $this->projectType->id,
            ])
            ->assertFormSet(['title' => 'Residential Design – Tester Client'])
            ->fillForm([
                'description' => '<h2>Requirements</h2><p>Plot: <strong>5 aana</strong></p><ul><li>3 bedrooms</li></ul>',
                'site_address' => 'Ward 5',
                'status' => 'inquiry',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $project = Project::firstOrFail();

        $this->assertSame('Residential Design – Tester Client', $project->title);
        $this->assertStringContainsString('<strong>5 aana</strong>', $project->descriptionHtml());

        $project->description = "Line one\nLine two";
        $this->assertSame("Line one<br />\nLine two", $project->descriptionHtml());
    }

    public function test_same_named_clients_are_told_apart_in_the_project_client_picker(): void
    {
        $twin = Client::create([
            'user_id' => User::factory()->create(['phone' => '9844444444'])->id,
            'contact_person' => 'Tester Client',
            'company_name' => 'ABC Traders',
            'client_type_id' => $this->client->client_type_id,
            'contact' => '9844444444',
            'address' => 'Kathmandu',
        ]);

        $this->assertSame('Tester Client — 9811111111 · Butwal', $this->client->selectLabel());
        $this->assertSame('Tester Client — 9844444444 · ABC Traders · Kathmandu', $twin->selectLabel());

        $options = Livewire::test(CreateProject::class)
            ->instance()
            ->form
            ->getComponent('data.client_id')
            ->getSearchResults('98444');

        $this->assertSame([$twin->id => $twin->selectLabel()], $options);
    }

    public function test_quotation_totals_and_accepting_sets_the_project_fee(): void
    {
        $project = $this->makeProject(['fee' => null]);

        $first = $project->quotations()->create(['status' => 'sent', 'discount' => 6000, 'vat_percent' => 13]);
        $first->items()->create(['description' => 'Architectural design', 'quantity' => 2400, 'unit' => 'sq.ft', 'rate' => 40]);
        $first->items()->create(['description' => 'Supervision', 'quantity' => 8000000, 'unit' => '%', 'rate' => 1]);

        $first->refresh();
        $this->assertSame(1, $first->version);
        $this->assertSame('176000.00', $first->subtotal);
        // (176,000 - 6,000) × 1.13
        $this->assertSame('192100.00', $first->total);

        $first->accept();
        $this->assertSame('192100.00', $project->refresh()->fee);

        $second = $project->quotations()->create(['status' => 'draft']);
        $second->items()->create(['description' => 'Revised lump sum', 'quantity' => 1, 'unit' => 'lump sum', 'rate' => 150000]);

        $this->assertSame(2, $second->refresh()->version);

        Livewire::test(QuotationsRelationManager::class, ['ownerRecord' => $project, 'pageClass' => EditProject::class])
            ->assertSuccessful()
            ->callTableAction('accept', $second);

        $this->assertSame('150000.00', $project->refresh()->fee);
        $this->assertSame('superseded', $first->refresh()->status);
        $this->assertSame('accepted', $second->refresh()->status);
    }

    public function test_an_accepted_quotation_locks_the_fee_and_acceptance_can_be_undone(): void
    {
        $project = $this->makeProject(['fee' => null]);

        $first = $project->quotations()->create(['status' => 'sent']);
        $first->items()->create(['description' => 'Design', 'quantity' => 1, 'unit' => 'lump sum', 'rate' => 20000]);
        $first->refresh()->accept();

        $second = $project->quotations()->create(['status' => 'sent']);
        $second->items()->create(['description' => 'Design (revised)', 'quantity' => 1, 'unit' => 'lump sum', 'rate' => 25000]);
        $second->refresh()->accept();

        // Typing over the fee is ignored while a quotation is accepted.
        $project->refresh()->update(['fee' => 0]);
        $this->assertSame('25000.00', $project->refresh()->fee);

        Livewire::test(EditProject::class, ['record' => $project->getRouteKey()])
            ->assertFormSet(['fee' => '25000.00'])
            ->assertFormFieldIsDisabled('fee');

        Livewire::test(QuotationsRelationManager::class, ['ownerRecord' => $project, 'pageClass' => EditProject::class])
            ->callTableAction('undoAcceptance', $second->refresh());

        $this->assertSame('sent', $second->refresh()->status);
        $this->assertSame('accepted', $first->refresh()->status);
        $this->assertSame('20000.00', $project->refresh()->fee);

        // Undone quotations can be deleted again.
        Livewire::test(QuotationsRelationManager::class, ['ownerRecord' => $project, 'pageClass' => EditProject::class])
            ->assertTableActionVisible('delete', $second)
            ->callTableAction('delete', $second);

        $this->assertModelMissing($second);
    }

    public function test_acceptance_cannot_be_undone_once_payments_depend_on_it(): void
    {
        $project = $this->makeProject(['fee' => null]);
        $quotation = $project->quotations()->create(['status' => 'sent']);
        $quotation->items()->create(['description' => 'Design', 'quantity' => 1, 'unit' => 'lump sum', 'rate' => 20000]);
        $quotation->refresh()->accept();

        Payment::create(['project_id' => $project->id, 'amount' => 2500, 'received_at' => now(), 'recorded_by' => $this->admin->id]);

        Livewire::test(QuotationsRelationManager::class, ['ownerRecord' => $project, 'pageClass' => EditProject::class])
            ->callTableAction('undoAcceptance', $quotation);

        $this->assertSame('accepted', $quotation->refresh()->status);
        $this->assertSame('20000.00', $project->refresh()->fee);
    }

    public function test_a_typed_fee_cannot_go_below_what_has_been_paid(): void
    {
        $project = $this->makeProject(['fee' => 25000]);
        Payment::create(['project_id' => $project->id, 'amount' => 2500, 'received_at' => now(), 'recorded_by' => $this->admin->id]);

        Livewire::test(EditProject::class, ['record' => $project->getRouteKey()])
            ->fillForm(['fee' => 0])
            ->call('save')
            ->assertHasFormErrors(['fee' => 'min']);
    }

    public function test_the_team_panel_only_shows_a_members_own_projects_and_tasks(): void
    {
        $mine = $this->makeProject(['title' => 'My project']);
        $other = $this->makeProject(['title' => 'Someone else\'s project']);

        [$member, $user] = $this->makeTeamMember('9822222222');
        [$colleague] = $this->makeTeamMember('9833333333');

        $mine->teamMembers()->attach($member);
        $myTask = Task::create(['project_id' => $mine->id, 'title' => 'Draw plans', 'assignee_id' => $member->id, 'status' => 'pending', 'created_by' => $this->admin->id]);
        $helpingTask = Task::create(['project_id' => $other->id, 'title' => 'Check BOQ', 'assignee_id' => $colleague->id, 'status' => 'pending', 'created_by' => $this->admin->id]);
        $helpingTask->members()->attach($member);
        $otherTask = Task::create(['project_id' => $other->id, 'title' => 'Site visit', 'assignee_id' => $colleague->id, 'status' => 'pending', 'created_by' => $this->admin->id]);

        $engineer = Role::create(['name' => 'engineer']);
        $engineer->givePermissionTo(['view_any_task', 'view_any_project']);
        $user->assignRole($engineer);

        Filament::setCurrentPanel(Filament::getPanel('team'));
        $this->actingAs($user);

        Livewire::test(ListTasks::class)
            ->assertCanSeeTableRecords([$myTask, $helpingTask])
            ->assertCanNotSeeTableRecords([$otherTask]);

        // Assigned to "mine"; helping on a task in "other".
        Livewire::test(ListProjects::class)
            ->assertCanSeeTableRecords([$mine, $other]);

        $helpingTask->members()->detach($member);

        Livewire::test(ListProjects::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_team_members_cannot_pick_a_super_admin_as_assignee_or_helper(): void
    {
        $project = $this->makeProject();
        [$member, $user] = $this->makeTeamMember('9822222222');
        [$colleague] = $this->makeTeamMember('9833333333');
        [$superAdminMember, $superAdminUser] = $this->makeTeamMember('9844444444');
        $superAdminUser->assignRole('super_admin');

        $project->teamMembers()->attach($member);
        $task = Task::create(['project_id' => $project->id, 'title' => 'Site measurement', 'assignee_id' => $member->id, 'status' => 'pending', 'created_by' => $this->admin->id]);
        $task->members()->attach($superAdminMember);

        $engineer = Role::create(['name' => 'engineer']);
        $engineer->givePermissionTo(['view_any_task', 'view_task', 'update_task']);
        $user->assignRole($engineer);

        Filament::setCurrentPanel(Filament::getPanel('team'));
        $this->actingAs($user);

        $form = Livewire::test(EditTask::class, ['record' => $task->getRouteKey()]);
        $components = $form->instance()->form;

        $assigneeOptions = $components->getComponent('data.assignee_id')->getOptions();
        $helperOptions = $components->getComponent('data.members')->getOptions();

        $this->assertArrayHasKey($colleague->id, $assigneeOptions);
        $this->assertArrayNotHasKey($superAdminMember->id, $assigneeOptions);
        $this->assertArrayHasKey($colleague->id, $helperOptions);
        $this->assertArrayNotHasKey($superAdminMember->id, $helperOptions);

        // A helper an admin already added is kept when a team member saves the task.
        $form->fillForm(['status' => 'in_progress'])->call('save')->assertHasNoFormErrors();

        $this->assertSame('in_progress', $task->refresh()->status);
        $this->assertTrue($task->members()->whereKey($superAdminMember->id)->exists());
    }

    public function test_the_admin_panel_still_shows_everything(): void
    {
        $project = $this->makeProject();
        [$member] = $this->makeTeamMember('9822222222');
        $task = Task::create(['project_id' => $project->id, 'title' => 'Draw plans', 'assignee_id' => $member->id, 'status' => 'pending', 'created_by' => $this->admin->id]);

        Livewire::test(ListTasks::class)->assertCanSeeTableRecords([$task]);
    }

    public function test_dashboard_widgets_show_behind_schedule_projects_and_overdue_tasks(): void
    {
        $late = $this->makeProject([
            'title' => 'Late house',
            'status' => 'designing',
            'start_date' => now()->subDays(60),
            'estimated_end_date' => now()->addDays(40),
        ]);
        $onTrack = $this->makeProject([
            'title' => 'Fresh house',
            'status' => 'designing',
            'start_date' => now(),
            'estimated_end_date' => now()->addDays(100),
        ]);

        $this->assertSame(60, $late->expectedProgress());
        $this->assertTrue($late->isBehindSchedule());
        $this->assertFalse($onTrack->isBehindSchedule());

        $overdue = Task::create(['project_id' => $late->id, 'title' => 'Overdue drawing', 'status' => 'in_progress', 'due_at' => now()->subDay(), 'created_by' => $this->admin->id]);
        Payment::create(['project_id' => $late->id, 'amount' => 20000, 'received_at' => now(), 'recorded_by' => $this->admin->id]);

        Livewire::test(ProjectStatsOverview::class)
            ->assertSuccessful()
            ->assertSee('Behind schedule')
            ->assertSee('NPR 180,000');

        Livewire::test(BehindScheduleProjects::class)
            ->assertSee('Late house')
            ->assertDontSee('Fresh house');

        Livewire::test(OverdueTasks::class)
            ->assertCanSeeTableRecords([$overdue]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function makeProject(array $attributes = []): Project
    {
        return Project::create([
            'client_id' => $this->client->id,
            'project_type_id' => $this->projectType->id,
            'title' => 'House Design',
            'description' => 'Two storey house',
            'site_address' => 'Ward 5',
            'status' => 'planning',
            'fee' => 100000,
            'created_by' => $this->admin->id,
            ...$attributes,
        ]);
    }

    /**
     * @return array{0: TeamMember, 1: User}
     */
    protected function makeTeamMember(string $phone): array
    {
        $user = User::factory()->create(['phone' => $phone]);

        $member = TeamMember::create([
            'user_id' => $user->id,
            'fullname' => "Member {$phone}",
            'contact1' => $phone,
            'marital_status' => 'Single',
            'national_id_path' => 'ids/id.jpg',
            'bank_name' => 'Nabil',
            'bank_account_name' => 'Member',
            'bank_account_number' => '111',
            'created_by' => $this->admin->id,
        ]);

        return [$member, $user];
    }
}
