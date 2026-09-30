<?php

namespace Tests\Feature;

use App\Filament\Client\Resources\ProjectResource\Pages\ViewProject as ClientViewProject;
use App\Filament\Client\Resources\ProjectResource\RelationManagers\SiteReportsRelationManager;
use App\Filament\Pages\LabourAttendanceSheet;
use App\Filament\Resources\MusterRollResource;
use App\Filament\Resources\MusterRollResource\Pages\CreateMusterRoll;
use App\Filament\Resources\MusterRollResource\Pages\EditMusterRoll;
use App\Filament\Resources\MusterRollResource\Pages\ListMusterRolls;
use App\Filament\Resources\MusterRollResource\Pages\ViewMusterRoll;
use App\Filament\Resources\SiteReportResource\Pages\CreateSiteReport;
use App\Filament\Resources\SiteReportResource\Pages\ViewSiteReport;
use App\Filament\Resources\WagePaymentResource;
use App\Models\Client;
use App\Models\ClientType;
use App\Models\LabourAttendance;
use App\Models\LabourContractor;
use App\Models\Labourer;
use App\Models\MusterRoll;
use App\Models\Project;
use App\Models\ProjectType;
use App\Models\SiteReport;
use App\Models\Task;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\WagePayment;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SiteOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $clientUser;

    protected User $supervisorUser;

    protected TeamMember $supervisor;

    protected Role $teamRole;

    protected Project $project;

    protected Project $otherProject;

    protected Labourer $mason;

    protected Labourer $helper;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-28 06:00:00'); // 11:45 AM, 12 Aswin 2083 in Nepal

        $this->admin = User::factory()->create(['phone' => '9800000000']);
        $this->admin->assignRole(Role::create(['name' => 'super_admin']));

        // approve_site_records and pay_labour_wages come from the site permissions migration.
        $this->teamRole = Role::create(['name' => 'team_member']);

        $this->clientUser = User::factory()->create(['phone' => '9811111111']);
        $client = Client::create([
            'user_id' => $this->clientUser->id,
            'contact_person' => 'Shishir Sharma',
            'client_type_id' => ClientType::create(['name' => 'Individual', 'slug' => 'individual'])->id,
            'contact' => '9811111111',
            'address' => 'Belbari',
        ]);

        $type = ProjectType::create(['name' => 'Residential', 'slug' => 'residential', 'is_active' => true]);
        $this->project = Project::create([
            'client_id' => $client->id, 'project_type_id' => $type->id, 'title' => 'House – Shishir Sharma',
            'description' => 'x', 'site_address' => 'Belbari', 'status' => 'execution', 'fee' => 1000000, 'created_by' => $this->admin->id,
        ]);
        $this->otherProject = Project::create([
            'client_id' => $client->id, 'project_type_id' => $type->id, 'title' => 'Shop – Itahari',
            'description' => 'x', 'site_address' => 'Itahari', 'status' => 'execution', 'fee' => 500000, 'created_by' => $this->admin->id,
        ]);

        $this->supervisorUser = User::factory()->create(['phone' => '9822222222']);
        $this->supervisorUser->assignRole($this->teamRole);
        $this->supervisor = TeamMember::create([
            'user_id' => $this->supervisorUser->id, 'fullname' => 'Hari Supervisor', 'contact1' => '9822222222', 'marital_status' => 'Single',
            'national_id_path' => 'ids/id.jpg', 'bank_name' => 'Nabil', 'bank_account_name' => 'Hari', 'bank_account_number' => '1',
            'created_by' => $this->admin->id,
        ]);
        $this->project->teamMembers()->attach($this->supervisor);

        $naike = LabourContractor::create(['name' => 'Ram Naike']);
        $this->mason = Labourer::create(['name' => 'Ram Bahadur', 'father_name' => 'Hari Bahadur', 'work_type' => 'mason', 'daily_wage' => 1500, 'labour_contractor_id' => $naike->id]);
        $this->helper = Labourer::create(['name' => 'Sita Kumari', 'father_name' => 'Gopal', 'work_type' => 'helper', 'daily_wage' => 1000]);
    }

    protected function actAs(User $user, string $panel): void
    {
        Filament::setCurrentPanel(Filament::getPanel($panel));
        $this->actingAs($user);
    }

    /**
     * Mark the mason and helper on this site for a few days of Aswin 2083 (Sep 17 – Oct 17, 2026).
     */
    protected function markAswinAttendance(): void
    {
        foreach (['2026-09-17', '2026-09-18', '2026-09-20'] as $date) {
            LabourAttendance::saveDay($this->project, $date, [
                $this->mason->id => ['status' => 'present', 'overtime_hours' => $date === '2026-09-20' ? 2 : 0],
                $this->helper->id => ['status' => $date === '2026-09-18' ? 'half_day' : 'present'],
            ], $this->supervisorUser);
        }
    }

    public function test_bikram_sambat_months_map_to_the_right_english_dates(): void
    {
        [$start, $end] = MusterRoll::periodFor('bs', 2083, 6);

        $this->assertSame('2026-09-17', $start->toDateString());
        $this->assertSame('2026-10-17', $end->toDateString());
        $this->assertSame([2083, 6], MusterRoll::monthOf('bs', '2026-09-28'));
        $this->assertSame('12 Aswin 2083', MusterRoll::bsDate('2026-09-28'));
    }

    public function test_attendance_snapshots_the_wage_and_pays_half_days_and_overtime(): void
    {
        $this->markAswinAttendance();

        $overtimeDay = LabourAttendance::query()->where('labourer_id', $this->mason->id)->whereDate('date', '2026-09-20')->sole();
        $this->assertSame(1875.0, $overtimeDay->wage()); // 1500 + 2h × 1500/8
        $this->assertSame('P+2', $overtimeDay->code());

        $this->mason->update(['daily_wage' => 1800]);
        $this->assertSame('1500.00', $overtimeDay->refresh()->wage_rate);

        $halfDay = LabourAttendance::query()->where('labourer_id', $this->helper->id)->whereDate('date', '2026-09-18')->sole();
        $this->assertSame(500.0, $halfDay->wage());
    }

    public function test_a_labourer_cannot_be_paid_at_two_sites_on_the_same_day(): void
    {
        LabourAttendance::saveDay($this->project, '2026-09-17', [$this->mason->id => ['status' => 'present']]);

        $this->expectException(ValidationException::class);

        LabourAttendance::saveDay($this->otherProject, '2026-09-17', [$this->mason->id => ['status' => 'present']]);
    }

    public function test_the_attendance_sheet_marks_a_day_for_a_site(): void
    {
        $this->actAs($this->supervisorUser, 'team');

        Livewire::withQueryParams(['project' => $this->project->id, 'date' => '2026-09-28'])
            ->test(LabourAttendanceSheet::class)
            ->callAction('addLabourer', ['labourer_ids' => [$this->mason->id, $this->helper->id]])
            ->call('setStatus', $this->helper->id, 'half_day')
            ->set("rows.{$this->mason->id}.overtime_hours", 1)
            ->call('save')
            ->assertNotified();

        $this->assertSame(2, LabourAttendance::query()->where('project_id', $this->project->id)->whereDate('date', '2026-09-28')->count());
        $this->assertSame('half_day', LabourAttendance::query()->where('labourer_id', $this->helper->id)->value('status'));

        // The next day the same crew is listed automatically.
        Livewire::withQueryParams(['project' => $this->project->id, 'date' => '2026-09-28'])
            ->test(LabourAttendanceSheet::class)
            ->assertSet("rows.{$this->mason->id}.status", 'present')
            ->assertSee('Ram Bahadur');
    }

    public function test_a_new_labourer_can_be_added_from_the_attendance_sheet(): void
    {
        $this->actAs($this->supervisorUser, 'team');

        Livewire::withQueryParams(['project' => $this->project->id, 'date' => '2026-09-28'])
            ->test(LabourAttendanceSheet::class)
            ->mountAction('newLabourer')
            ->assertSee('Came through (naike)')
            ->setActionData(['name' => 'Bikash Rai', 'father_name' => 'Man Bdr Rai', 'work_type' => 'carpenter', 'daily_wage' => 1400, 'labour_contractor_id' => $this->mason->labour_contractor_id])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertSet('rows.'.Labourer::where('name', 'Bikash Rai')->value('id').'.status', 'present');

        $this->assertSame($this->mason->labour_contractor_id, Labourer::where('name', 'Bikash Rai')->value('labour_contractor_id'));
    }

    public function test_parts_two_and_three_are_edited_on_the_edit_page(): void
    {
        $this->markAswinAttendance();
        $roll = MusterRoll::create(['project_id' => $this->project->id, 'calendar' => 'bs', 'year' => 2083, 'month' => 6, 'prepared_by' => $this->supervisorUser->id]);
        $this->actAs($this->supervisorUser, 'team');

        $page = Livewire::test(EditMusterRoll::class, ['record' => $roll->getRouteKey()])
            ->assertOk()
            ->assertSee('Ram Bahadur');

        $lines = $page->get('data.lines');
        $masonKey = collect($lines)->search(fn (array $line): bool => (int) $line['labourer_id'] === $this->mason->id);

        $page->set("data.lines.{$masonKey}.arrear_reason", 'Went home for Dashain')
            ->set('data.works', [(string) Str::uuid() => ['labourer_id' => $this->mason->id, 'description' => 'Brick masonry 1:6', 'quantity' => 12.5, 'unit' => 'm³', 'mb_ref' => '14', 'remarks' => null]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Went home for Dashain', $roll->lines()->where('labourer_id', $this->mason->id)->value('arrear_reason'));
        $this->assertSame('14', $roll->works()->sole()->mb_ref);
    }

    public function test_admins_delete_submitted_rolls_but_approved_ones_must_be_returned_first(): void
    {
        $this->markAswinAttendance();
        $roll = MusterRoll::create(['project_id' => $this->project->id, 'calendar' => 'bs', 'year' => 2083, 'month' => 6, 'prepared_by' => $this->supervisorUser->id]);
        $roll->submit($this->supervisorUser);

        // The supervisor cannot delete once submitted; the admin can, which unlocks the month.
        $this->actAs($this->supervisorUser, 'team');
        $this->assertFalse(MusterRollResource::canDelete($roll));

        $this->actAs($this->admin, 'admin');
        $this->assertTrue(MusterRollResource::canDelete($roll));

        $roll->approve($this->admin);
        $this->assertFalse(MusterRollResource::canDelete($roll->refresh()));

        Livewire::test(ViewMusterRoll::class, ['record' => $roll->getRouteKey()])
            ->assertActionHidden('delete')
            ->callAction('return', ['note' => 'Recount days']);

        // Once returned (page reloaded), the admin can delete it.
        Livewire::test(ViewMusterRoll::class, ['record' => $roll->getRouteKey()])
            ->assertActionVisible('delete')
            ->callAction('delete');

        $this->assertModelMissing($roll);
        $this->assertSame(6, LabourAttendance::count()); // attendance is kept
        LabourAttendance::saveDay($this->project, '2026-09-19', [$this->helper->id => ['status' => 'present']]); // and unlocked
    }

    public function test_team_members_only_see_their_own_sites(): void
    {
        $this->actAs($this->supervisorUser, 'team');

        Livewire::test(CreateMusterRoll::class)
            ->fillForm(['project_id' => $this->otherProject->id, 'calendar' => 'bs', 'year' => 2083, 'month' => 6])
            ->call('create')
            ->assertHasFormErrors(['project_id']);

        MusterRoll::create(['project_id' => $this->otherProject->id, 'calendar' => 'bs', 'year' => 2083, 'month' => 6]);
        $own = MusterRoll::create(['project_id' => $this->project->id, 'calendar' => 'bs', 'year' => 2083, 'month' => 6]);

        Livewire::test(ListMusterRolls::class)
            ->assertCanSeeTableRecords([$own])
            ->assertCountTableRecords(1);
    }

    public function test_muster_roll_builds_part_one_and_locks_attendance_once_submitted(): void
    {
        $this->markAswinAttendance();
        $this->actAs($this->supervisorUser, 'team');

        Livewire::test(CreateMusterRoll::class)
            ->fillForm(['project_id' => $this->project->id, 'calendar' => 'bs', 'year' => 2083, 'month' => 6])
            ->call('create')
            ->assertHasNoFormErrors();

        $roll = MusterRoll::sole();
        $this->assertSame('Aswin 2083', $roll->label());
        $this->assertSame($this->supervisorUser->id, $roll->prepared_by);

        $masonLine = $roll->lines()->where('labourer_id', $this->mason->id)->sole();
        $this->assertSame(['1' => 'P', '2' => 'P', '4' => 'P+2'], $masonLine->days);
        $this->assertSame('3.0', $masonLine->present_days);
        $this->assertSame('4875.00', $masonLine->total_wage); // 1500 × 3 + 375 OT
        $this->assertSame(7375.0, $roll->totalWage()); // + helper 1000 + 500 + 1000

        // A second roll for the same month is refused.
        Livewire::test(CreateMusterRoll::class)
            ->fillForm(['project_id' => $this->project->id, 'calendar' => 'ad', 'year' => 2026, 'month' => 10])
            ->call('create')
            ->assertHasFormErrors(['year']);

        Livewire::test(ViewMusterRoll::class, ['record' => $roll->getRouteKey()])
            ->assertActionHidden('approve')
            ->callAction('submit');

        $this->assertSame('submitted', $roll->refresh()->status);
        $this->assertSame('Muster roll to approve: Aswin 2083', $this->admin->notifications()->sole()->data['title']);

        $this->expectException(ValidationException::class);
        LabourAttendance::saveDay($this->project, '2026-09-19', [$this->helper->id => ['status' => 'present']]);
    }

    public function test_approval_needs_the_permission_and_returning_unlocks(): void
    {
        $this->markAswinAttendance();
        $roll = MusterRoll::create(['project_id' => $this->project->id, 'calendar' => 'bs', 'year' => 2083, 'month' => 6, 'prepared_by' => $this->supervisorUser->id]);
        $roll->submit($this->supervisorUser);

        $this->actAs($this->supervisorUser, 'team');
        Livewire::test(ViewMusterRoll::class, ['record' => $roll->getRouteKey()])->assertActionHidden('approve');

        // The super admin grants the power to the team role.
        $this->teamRole->givePermissionTo('approve_site_records');
        $this->supervisorUser->refresh();

        Livewire::test(ViewMusterRoll::class, ['record' => $roll->getRouteKey()])
            ->callAction('return', ['note' => 'Sita was absent on day 2']);

        $this->assertSame('returned', $roll->refresh()->status);
        LabourAttendance::saveDay($this->project, '2026-09-18', [$this->helper->id => ['status' => 'absent']]);

        $roll->submit($this->supervisorUser);
        Livewire::test(ViewMusterRoll::class, ['record' => $roll->getRouteKey()])
            ->callAction('approve');

        $this->assertSame('approved', $roll->refresh()->status);
        $this->assertSame(6875.0, $roll->totalWage());
    }

    public function test_advances_are_deducted_and_part_two_tracks_unpaid_wages(): void
    {
        $this->markAswinAttendance();
        $roll = MusterRoll::create(['project_id' => $this->project->id, 'calendar' => 'bs', 'year' => 2083, 'month' => 6]);

        // An advance to the mason before the month is approved.
        WagePayment::create(['project_id' => $this->project->id, 'labourer_id' => $this->mason->id, 'type' => 'advance', 'amount' => 2000, 'paid_on' => '2026-09-19']);
        $this->assertSame(-2000.0, $this->mason->balance());

        $roll->submit($this->supervisorUser);
        $roll->approve($this->admin);

        $this->assertSame(2875.0, $this->mason->balance());
        $arrears = collect($roll->arrears())->keyBy(fn (array $row): int => $row['line']->labourer_id);
        $this->assertSame(2000.0, $arrears[$this->mason->id]['paid']);
        $this->assertSame(2875.0, $arrears[$this->mason->id]['unpaid']);
        $this->assertSame(2500.0, $arrears[$this->helper->id]['unpaid']);

        // Paying through the naike lists only the naike's labourers; overpaying becomes an advance.
        $this->actAs($this->admin, 'admin');
        Livewire::test(ViewMusterRoll::class, ['record' => $roll->getRouteKey()])
            ->mountAction('payWages')
            ->setActionData(['labour_contractor_id' => $this->mason->labour_contractor_id])
            ->assertActionDataSet(['payments' => fn ($payments): bool => count($payments) === 1])
            ->setActionData(['payments' => [['labourer_id' => $this->mason->id, 'name' => 'Ram Bahadur', 'due' => 2875, 'amount' => 3000]]])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame(-125.0, $this->mason->balance());
        $this->assertEqualsCanonicalizing(['wage', 'advance'], WagePayment::query()->where('muster_roll_id', $roll->id)->pluck('type')->all());
        $this->assertSame([$this->helper->id], collect($roll->arrears())->map(fn (array $row): int => $row['line']->labourer_id)->all());
    }

    public function test_the_print_view_follows_site_access(): void
    {
        $this->markAswinAttendance();
        $roll = MusterRoll::create(['project_id' => $this->project->id, 'calendar' => 'bs', 'year' => 2083, 'month' => 6]);
        $other = MusterRoll::create(['project_id' => $this->otherProject->id, 'calendar' => 'bs', 'year' => 2083, 'month' => 6]);

        $this->actingAs($this->supervisorUser)
            ->get(route('site.muster-rolls.print', $roll))
            ->assertOk()
            ->assertSee('Part I: Nominal Roll', false)
            ->assertSee('Ram Bahadur')
            ->assertSee('Hari Bahadur')
            ->assertSee('P+2');

        $this->get(route('site.muster-rolls.print', $other))->assertForbidden();
    }

    public function test_site_reports_are_submitted_approved_and_shown_to_the_client(): void
    {
        LabourAttendance::saveDay($this->project, '2026-09-28', [
            $this->mason->id => ['status' => 'present'],
            $this->helper->id => ['status' => 'present'],
        ]);
        $task = Task::create(['project_id' => $this->project->id, 'title' => 'Ground floor slab', 'status' => 'in_progress', 'created_by' => $this->admin->id]);

        $this->actAs($this->supervisorUser, 'team');
        Livewire::test(CreateSiteReport::class)
            ->fillForm(['project_id' => $this->project->id, 'date' => '2026-09-28'])
            ->assertFormSet(['manpower' => fn ($rows): bool => collect($rows)->sum('count') === 2])
            ->fillForm([
                'weather' => 'sunny',
                'work_done' => 'Ground floor slab concreting completed.',
                'work_items' => [['description' => 'Slab concreting', 'quantity' => 18, 'unit' => 'm³', 'task_id' => $task->id, 'task_completed' => true]],
                'next_day_plan' => 'Curing, start brick work.',
            ])
            ->call('createAndSubmit')
            ->assertHasNoFormErrors();

        $report = SiteReport::sole();
        $this->assertSame('submitted', $report->status);
        $this->assertSame(2, $report->totalManpower());

        // One report per site per day.
        Livewire::test(CreateSiteReport::class)
            ->fillForm(['project_id' => $this->project->id, 'date' => '2026-09-28', 'work_done' => 'Again'])
            ->call('create')
            ->assertHasFormErrors(['date']);

        // The client sees nothing until it is approved.
        $this->actAs($this->clientUser, 'client');
        Livewire::test(SiteReportsRelationManager::class, ['ownerRecord' => $this->project, 'pageClass' => ClientViewProject::class])
            ->assertCountTableRecords(0);

        $this->actAs($this->admin, 'admin');
        Livewire::test(ViewSiteReport::class, ['record' => $report->getRouteKey()])
            ->callAction('approve');

        $this->assertSame('completed', $task->refresh()->status);
        $this->assertStringStartsWith('Site update:', $this->clientUser->notifications()->sole()->data['title']);
        $this->assertSame('Site report approved: Sep 28', $this->supervisorUser->notifications()->latest('id')->first()->data['title']);

        $this->actAs($this->clientUser, 'client');
        Livewire::test(SiteReportsRelationManager::class, ['ownerRecord' => $this->project, 'pageClass' => ClientViewProject::class])
            ->assertCanSeeTableRecords([$report]);
    }

    public function test_the_evening_reminder_goes_to_teams_without_todays_report(): void
    {
        SiteReport::create(['project_id' => $this->otherProject->id, 'date' => '2026-09-28', 'work_done' => 'Done', 'status' => 'draft']);
        $this->otherProject->teamMembers()->attach($this->supervisor);

        $this->artisan('site:send-reminders')->assertSuccessful();

        $notification = $this->supervisorUser->notifications()->sole();
        $this->assertSame("Today's site report is missing", $notification->data['title']);
        $this->assertStringContainsString('House – Shishir Sharma', $notification->data['body']);
    }

    public function test_site_screens_are_open_to_team_members_but_wages_need_the_permission(): void
    {
        $this->actAs($this->supervisorUser, 'team');

        $this->assertTrue(MusterRollResource::canViewAny());
        $this->assertFalse(WagePaymentResource::canViewAny());

        $this->teamRole->givePermissionTo('pay_labour_wages');
        $this->supervisorUser->refresh();

        $this->assertTrue(WagePaymentResource::canViewAny());
    }
}
