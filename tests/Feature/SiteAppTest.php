<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientType;
use App\Models\LabourAttendance;
use App\Models\Labourer;
use App\Models\MusterRoll;
use App\Models\Project;
use App\Models\ProjectType;
use App\Models\SiteReport;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SiteAppTest extends TestCase
{
    use RefreshDatabase;

    protected User $supervisorUser;

    protected Project $project;

    protected Project $otherProject;

    protected Labourer $mason;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-28 06:00:00');

        $admin = User::factory()->create(['phone' => '9800000000']);
        $admin->assignRole(Role::create(['name' => 'super_admin']));

        $client = Client::create([
            'user_id' => User::factory()->create(['phone' => '9811111111'])->id,
            'contact_person' => 'Shishir Sharma',
            'client_type_id' => ClientType::create(['name' => 'Individual', 'slug' => 'individual'])->id,
            'contact' => '9811111111',
            'address' => 'Belbari',
        ]);
        $type = ProjectType::create(['name' => 'Residential', 'slug' => 'residential', 'is_active' => true]);
        $this->project = Project::create(['client_id' => $client->id, 'project_type_id' => $type->id, 'title' => 'House – Belbari', 'description' => 'x', 'site_address' => 'Belbari', 'status' => 'execution', 'created_by' => $admin->id]);
        $this->otherProject = Project::create(['client_id' => $client->id, 'project_type_id' => $type->id, 'title' => 'Shop – Itahari', 'description' => 'x', 'site_address' => 'Itahari', 'status' => 'execution', 'created_by' => $admin->id]);

        $this->supervisorUser = User::factory()->create(['phone' => '9822222222']);
        $this->supervisorUser->assignRole(Role::create(['name' => 'team_member']));
        $supervisor = TeamMember::create([
            'user_id' => $this->supervisorUser->id, 'fullname' => 'Hari Supervisor', 'contact1' => '9822222222', 'marital_status' => 'Single',
            'national_id_path' => 'ids/id.jpg', 'bank_name' => 'Nabil', 'bank_account_name' => 'Hari', 'bank_account_number' => '1',
            'created_by' => $admin->id,
        ]);
        $this->project->teamMembers()->attach($supervisor);

        $this->mason = Labourer::create(['name' => 'Ram Bahadur', 'work_type' => 'mason', 'daily_wage' => 1500]);
    }

    public function test_guests_are_sent_to_the_team_login_and_json_calls_get_401(): void
    {
        $this->get(route('site.app'))->assertRedirect('/team/login');
        $this->getJson(route('site.app.data'))->assertUnauthorized();
    }

    public function test_the_app_downloads_only_the_users_sites(): void
    {
        LabourAttendance::saveDay($this->project, '2026-09-27', [$this->mason->id => ['status' => 'present']]);

        $this->actingAs($this->supervisorUser)->get(route('site.app'))->assertOk()->assertSee('CivoraX');

        $response = $this->getJson(route('site.app.data'))->assertOk();

        $this->assertSame([$this->project->id], array_column($response->json('projects'), 'id'));
        $this->assertSame([$this->mason->id], $response->json('projects.0.crew'));
        $this->assertSame('present', $response->json("attendance.{$this->project->id}|2026-09-27.{$this->mason->id}.status"));
        $this->assertSame('12 Aswin 2083', $response->json('today_bs'));
    }

    public function test_queued_attendance_is_saved_and_other_sites_are_refused(): void
    {
        $this->actingAs($this->supervisorUser);

        $this->postJson(route('site.app.attendance'), [
            'project_id' => $this->project->id,
            'date' => '2026-09-28',
            'rows' => [$this->mason->id => ['status' => 'half_day', 'overtime_hours' => 1]],
        ])->assertOk();

        $this->assertSame('half_day', LabourAttendance::sole()->status);

        $this->postJson(route('site.app.attendance'), [
            'project_id' => $this->otherProject->id,
            'date' => '2026-09-28',
            'rows' => [$this->mason->id => ['status' => 'present']],
        ])->assertUnprocessable()->assertJsonValidationErrors('project_id');
    }

    public function test_attendance_for_a_locked_month_is_refused_with_a_reason(): void
    {
        LabourAttendance::saveDay($this->project, '2026-09-20', [$this->mason->id => ['status' => 'present']]);
        MusterRoll::create(['project_id' => $this->project->id, 'calendar' => 'bs', 'year' => 2083, 'month' => 6])->submit($this->supervisorUser);

        $this->actingAs($this->supervisorUser)->postJson(route('site.app.attendance'), [
            'project_id' => $this->project->id,
            'date' => '2026-09-21',
            'rows' => [$this->mason->id => ['status' => 'present']],
        ])->assertUnprocessable()->assertJsonValidationErrors('date');
    }

    public function test_offline_reports_upload_with_photos_once_even_if_retried(): void
    {
        Storage::fake('public');
        $this->actingAs($this->supervisorUser);

        $payload = [
            'client_uuid' => (string) Str::uuid(),
            'project_id' => $this->project->id,
            'date' => '2026-09-27',
            'weather' => 'cloudy',
            'work_done' => 'Plinth beam shuttering.',
            'manpower' => [['trade' => 'Mason (Dakarmi)', 'count' => 3]],
            'latitude' => 26.66,
            'longitude' => 87.28,
            'photos' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')],
        ];

        $this->post(route('site.app.reports'), $payload, ['Accept' => 'application/json'])->assertCreated();
        $this->post(route('site.app.reports'), $payload, ['Accept' => 'application/json'])->assertOk();

        $report = SiteReport::sole();
        $this->assertSame('submitted', $report->status);
        $this->assertSame(3, $report->totalManpower());
        $this->assertCount(2, $report->photos);
        Storage::disk('public')->assertExists($report->photos[0]);
    }
}
