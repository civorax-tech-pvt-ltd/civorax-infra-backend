<?php

namespace Tests\Feature;

use App\Filament\Resources\ProjectResource\Pages\CreateProject;
use App\Filament\Resources\ProjectResource\Pages\EditProject;
use App\Filament\Resources\ProjectResource\RelationManagers\MilestonesRelationManager;
use App\Filament\Resources\ProjectResource\RelationManagers\TasksRelationManager;
use App\Filament\Resources\ProjectTypeResource\Pages\EditProjectType;
use App\Filament\Resources\TaskResource\Pages\CreateTask;
use App\Models\Client;
use App\Models\ClientType;
use App\Models\Project;
use App\Models\ProjectType;
use App\Models\Task;
use App\Models\TeamMember;
use App\Models\User;
use Database\Seeders\MilestoneTemplateSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProjectProgressTrackingTest extends TestCase
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

        foreach (['project', 'project::milestone', 'task', 'project::type'] as $resource) {
            foreach (['view_any', 'view', 'create', 'update', 'delete'] as $action) {
                $superAdmin->givePermissionTo(Permission::create(['name' => "{$action}_{$resource}"]));
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

        $this->addTemplate('Planning & Survey', 'planning', 1, 20, [['Site survey', 1], ['Requirement meeting', 1]]);
        $this->addTemplate('Architectural Design', 'designing', 2, 30, [['2D floor plans', 1], ['3D design', 3]]);
        $this->addTemplate('Municipality Approval', 'awaiting_approval', 3, 50, [['Submit drawings', 1]]);
    }

    public function test_creating_a_project_adds_the_template_milestones_and_tasks(): void
    {
        Livewire::test(CreateProject::class)
            ->fillForm([
                'client_id' => $this->client->id,
                'project_type_id' => $this->projectType->id,
                'title' => 'House Design',
                'description' => 'Two storey house',
                'site_address' => 'Ward 5',
                'status' => 'planning',
                'fee' => 100000,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $project = Project::where('title', 'House Design')->firstOrFail();

        $this->assertSame(
            ['Planning & Survey', 'Architectural Design', 'Municipality Approval'],
            $project->milestones()->orderBy('sequence')->pluck('title')->all(),
        );
        $this->assertSame(5, $project->tasks()->count());
        $this->assertSame(0, $project->tasks()->whereNotNull('assignee_id')->count());
        $this->assertSame('planning', $project->refresh()->status);
    }

    public function test_task_progress_rolls_up_to_the_milestone_and_the_project(): void
    {
        $project = $this->makeProject();
        [$planning, $design] = $project->milestones()->orderBy('sequence')->get();

        $design->tasks()->where('title', '3D design')->first()->update(['status' => 'completed']);

        $design->refresh();
        $this->assertSame(75, $design->progress);
        $this->assertSame('in_progress', $design->status);

        // Weighted by billing: 30% of the value is 75% done => 22.5 => 23.
        $this->assertSame(23, $project->refresh()->progress);
        $this->assertSame('planning', $project->status);
    }

    public function test_a_milestone_waits_for_confirmation_then_the_project_moves_to_the_next_phase(): void
    {
        $project = $this->makeProject();
        $planning = $project->milestones()->orderBy('sequence')->first();

        $planning->tasks->each->update(['status' => 'completed']);

        $planning->refresh();
        $this->assertSame(100, $planning->progress);
        $this->assertSame('in_progress', $planning->status);
        $this->assertTrue($planning->isReadyToComplete());

        $planning->update(['status' => 'completed']);

        $this->assertNotNull($planning->refresh()->completed_at);
        $this->assertSame('designing', $project->refresh()->status);
        $this->assertSame(20, $project->progress);
    }

    public function test_the_project_completes_when_every_milestone_is_completed(): void
    {
        $project = $this->makeProject();

        $project->milestones->each->update(['status' => 'completed']);

        $project->refresh();
        $this->assertSame('completed', $project->status);
        $this->assertSame(100, $project->progress);
    }

    public function test_on_hold_is_never_overridden(): void
    {
        $project = $this->makeProject();
        $project->update(['status' => 'on_hold']);

        $project->milestones()->first()->update(['status' => 'completed']);

        $this->assertSame('on_hold', $project->refresh()->status);
        $this->assertSame(20, $project->progress);
    }

    public function test_moving_a_task_to_another_milestone_recalculates_both(): void
    {
        $project = $this->makeProject();
        [$planning, $design] = $project->milestones()->orderBy('sequence')->get();

        $task = $planning->tasks()->first();
        $task->update(['status' => 'completed']);
        $this->assertSame(50, $planning->refresh()->progress);

        $task->update(['milestone_id' => $design->id]);

        $this->assertSame(0, $planning->refresh()->progress);
        // Design now weighs 1 + 3 + 1 with only the moved task (weight 1) done.
        $this->assertSame(20, $design->refresh()->progress);
    }

    public function test_a_task_takes_its_project_from_its_milestone(): void
    {
        $project = $this->makeProject();
        $milestone = $project->milestones()->first();
        $member = $this->makeTeamMember();

        Livewire::test(CreateTask::class)
            ->fillForm([
                'project_id' => $project->id,
                'milestone_id' => $milestone->id,
                'title' => 'Soil test follow-up',
                'assignee_id' => $member->id,
                'status' => 'in_progress',
                'weight' => 2,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $task = Task::where('title', 'Soil test follow-up')->firstOrFail();

        $this->assertSame($project->id, $task->project_id);
        $this->assertSame('in_progress', $milestone->refresh()->status);
    }

    public function test_the_assignee_is_never_saved_as_their_own_helper(): void
    {
        $project = $this->makeProject();
        $assignee = $this->makeTeamMember();
        $helper = TeamMember::create([
            'user_id' => User::factory()->create(['phone' => '9833333333'])->id,
            'fullname' => 'Saugat Dhungana',
            'contact1' => '9833333333',
            'marital_status' => 'Single',
            'national_id_path' => 'ids/saugat.jpg',
            'bank_name' => 'Nabil',
            'bank_account_name' => 'Saugat',
            'bank_account_number' => '222',
            'created_by' => $this->admin->id,
        ]);

        Livewire::test(CreateTask::class)
            ->fillForm([
                'project_id' => $project->id,
                'title' => 'Site measurement',
                'assignee_id' => $assignee->id,
                'members' => [$assignee->id, $helper->id],
                'status' => 'pending',
                'weight' => 1,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(
            [$helper->id],
            Task::where('title', 'Site measurement')->firstOrFail()->members()->pluck('team_members.id')->all(),
        );
    }

    public function test_the_starter_template_seeder_fills_empty_types_and_leaves_edited_ones_alone(): void
    {
        $interior = ProjectType::create(['name' => 'Interior', 'slug' => 'interior', 'is_active' => true]);

        $this->seed(MilestoneTemplateSeeder::class);
        $this->seed(MilestoneTemplateSeeder::class);

        $templates = $interior->milestoneTemplates()->withCount('tasks')->get();

        $this->assertSame(['Site Survey & Brief', 'Interior Design', 'Estimation & Approval', 'Execution'], $templates->pluck('title')->all());
        $this->assertEquals(100, $templates->sum('billing_percent'));
        $this->assertSame(14, $templates->sum('tasks_count'));

        // "Residential Design" already has its own templates from setUp, so it is untouched.
        $this->assertSame(3, $this->projectType->milestoneTemplates()->count());
    }

    public function test_project_page_tabs_and_project_type_template_editor_render(): void
    {
        $project = $this->makeProject();

        Livewire::test(EditProject::class, ['record' => $project->getRouteKey()])->assertSuccessful();

        Livewire::test(MilestonesRelationManager::class, ['ownerRecord' => $project, 'pageClass' => EditProject::class])
            ->assertSuccessful()
            ->assertCanSeeTableRecords($project->milestones);

        Livewire::test(TasksRelationManager::class, ['ownerRecord' => $project, 'pageClass' => EditProject::class])
            ->assertSuccessful()
            ->assertCanSeeTableRecords($project->tasks);

        Livewire::test(EditProjectType::class, ['record' => $this->projectType->getRouteKey()])->assertSuccessful();
    }

    /**
     * @param  list<array{0: string, 1: int}>  $tasks
     */
    protected function addTemplate(string $title, string $phase, int $sequence, float $billingPercent, array $tasks): void
    {
        $template = $this->projectType->milestoneTemplates()->create([
            'title' => $title,
            'phase' => $phase,
            'sequence' => $sequence,
            'billing_percent' => $billingPercent,
        ]);

        foreach ($tasks as $index => [$taskTitle, $weight]) {
            $template->tasks()->create(['title' => $taskTitle, 'weight' => $weight, 'sort' => $index]);
        }
    }

    protected function makeProject(): Project
    {
        $project = Project::create([
            'client_id' => $this->client->id,
            'project_type_id' => $this->projectType->id,
            'title' => 'House Design',
            'description' => 'Two storey house',
            'site_address' => 'Ward 5',
            'status' => 'planning',
            'fee' => 100000,
            'created_by' => $this->admin->id,
        ]);

        $project->applyMilestoneTemplates();

        return $project->refresh();
    }

    protected function makeTeamMember(): TeamMember
    {
        return TeamMember::create([
            'user_id' => User::factory()->create(['phone' => '9822222222'])->id,
            'fullname' => 'Hari Bdr',
            'contact1' => '9822222222',
            'marital_status' => 'Single',
            'national_id_path' => 'ids/hari.jpg',
            'bank_name' => 'Nabil',
            'bank_account_name' => 'Hari Bdr',
            'bank_account_number' => '111',
            'created_by' => $this->admin->id,
        ]);
    }
}
