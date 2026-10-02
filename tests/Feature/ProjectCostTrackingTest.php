<?php

namespace Tests\Feature;

use App\Filament\Pages\ManageApprovalSettings;
use App\Filament\Resources\BoqMeasurementResource\Pages\ManageBoqMeasurements;
use App\Filament\Resources\ProjectResource;
use App\Filament\Resources\ProjectResource\Pages\EditProject;
use App\Filament\Resources\ProjectResource\Pages\ProjectCosts;
use App\Filament\Resources\ProjectResource\RelationManagers\BoqItemsRelationManager;
use App\Filament\Resources\ProjectResource\RelationManagers\QuotationsRelationManager;
use App\Models\BoqItem;
use App\Models\BoqMasterItem;
use App\Models\BoqMeasurement;
use App\Models\Client;
use App\Models\ClientType;
use App\Models\CompanySetting;
use App\Models\LabourAttendance;
use App\Models\Labourer;
use App\Models\MusterRoll;
use App\Models\Project;
use App\Models\ProjectCost;
use App\Models\ProjectType;
use App\Models\TeamMember;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProjectCostTrackingTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $engineerUser;

    protected Role $engineerRole;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-01 06:00:00');

        $this->admin = User::factory()->create(['phone' => '9800000000']);
        $superAdmin = Role::create(['name' => 'super_admin']);
        foreach (['view_any_project', 'view_project', 'update_project'] as $permission) {
            $superAdmin->givePermissionTo(Permission::create(['name' => $permission]));
        }
        $this->admin->assignRole($superAdmin);

        $client = Client::create([
            'user_id' => User::factory()->create(['phone' => '9811111111'])->id,
            'contact_person' => 'Shishir Sharma',
            'client_type_id' => ClientType::create(['name' => 'Individual', 'slug' => 'individual'])->id,
            'contact' => '9811111111',
            'address' => 'Belbari',
        ]);
        $this->project = Project::create([
            'client_id' => $client->id, 'project_type_id' => ProjectType::create(['name' => 'Residential', 'slug' => 'residential', 'is_active' => true])->id,
            'title' => 'House – Belbari', 'description' => 'x', 'site_address' => 'Belbari', 'status' => 'execution', 'fee' => 1800000, 'created_by' => $this->admin->id,
        ]);

        $this->engineerRole = Role::create(['name' => 'site_engineer']);
        $this->engineerUser = User::factory()->create(['phone' => '9822222222']);
        $this->engineerUser->assignRole($this->engineerRole);
        $engineer = TeamMember::create([
            'user_id' => $this->engineerUser->id, 'fullname' => 'Hari Engineer', 'contact1' => '9822222222', 'marital_status' => 'Single',
            'national_id_path' => 'ids/id.jpg', 'bank_name' => 'Nabil', 'bank_account_name' => 'Hari', 'bank_account_number' => '1',
            'created_by' => $this->admin->id,
        ]);
        $this->project->teamMembers()->attach($engineer);
    }

    protected function boqItem(float $quantity, float $rate, array $extra = []): BoqItem
    {
        return $this->project->boqItems()->create(['description' => 'RCC in slab', 'unit' => 'm³', 'quantity' => $quantity, 'rate' => $rate, ...$extra]);
    }

    protected function approvedMeasurement(BoqItem $item, float $executed, string $date = '2026-09-30'): BoqMeasurement
    {
        $measurement = $item->measurements()->create(['measured_date' => $date, 'executed_quantity' => $executed, 'status' => 'pending', 'entered_by' => $this->engineerUser->id]);
        $measurement->approve($this->admin);

        return $measurement;
    }

    protected function budget(array $amounts): void
    {
        foreach ($amounts as $category => $amount) {
            $this->project->budgets()->create(['category' => $category, 'amount' => $amount]);
        }
    }

    protected function cost(string $category, float $amount): void
    {
        static $id = 1000;
        ProjectCost::create(['project_id' => $this->project->id, 'category' => $category, 'source_type' => 'test', 'source_id' => $id++, 'amount' => $amount, 'entry_date' => today()]);
    }

    // ── Acceptance check 7: BOQ progress ──────────────────────────────────────

    public function test_item_progress_uses_the_latest_approved_cumulative_quantity_and_flags_excess(): void
    {
        $item = $this->boqItem(45, 12000);

        $this->approvedMeasurement($item, 12, '2026-09-10');
        $this->approvedMeasurement($item, 30, '2026-09-25');
        // Pending and rejected entries never count.
        $item->measurements()->create(['measured_date' => '2026-09-30', 'executed_quantity' => 40, 'status' => 'pending', 'entered_by' => $this->engineerUser->id]);

        $item->refresh();
        $this->assertSame(66.7, $item->progressPercent()); // 30 of 45 ≈ 67%
        $this->assertSame('in_progress', $item->status());
        $this->assertFalse($item->isExcess());

        $this->approvedMeasurement($item, 48, '2026-09-30');
        $item->refresh();
        $this->assertTrue($item->isExcess());
        $this->assertSame(106.7, $item->progressPercent()); // not capped silently
        $this->assertSame('completed', $item->status());
    }

    public function test_project_progress_is_weighted_by_value_and_schedule_status_follows_planned_dates(): void
    {
        $big = $this->boqItem(100, 1000, ['planned_start' => '2026-09-01', 'planned_end' => '2026-10-31']); // Rs 100,000
        $small = $this->boqItem(10, 1000, ['description' => 'Plaster']);                                        // Rs 10,000

        $this->approvedMeasurement($big, 10);
        $this->approvedMeasurement($small, 10);

        // (10×1000 + 10×1000) ÷ 110,000 = 18.2%, not the simple average of 10% and 100%.
        $this->assertSame(18.2, $this->project->boqProgress());

        $big->refresh();
        $this->assertSame(50.0, $big->plannedPercentToday()); // Oct 1 is half way through Sep 1 – Oct 31
        $this->assertSame('delayed', $big->scheduleStatus());
        $this->assertNull($small->refresh()->scheduleStatus());
    }

    // ── Approvals (§13.3) ─────────────────────────────────────────────────────

    public function test_nobody_approves_their_own_measurement_and_approvers_are_configurable(): void
    {
        $item = $this->boqItem(45, 12000);
        $measurement = $item->measurements()->create(['measured_date' => '2026-09-30', 'executed_quantity' => 30, 'status' => 'pending', 'entered_by' => $this->engineerUser->id]);

        // Approvers were notified.
        $this->assertStringStartsWith('Measurement to approve', $this->admin->notifications()->sole()->data['title']);

        // The engineer has no approval power yet.
        $this->assertFalse(BoqMeasurement::canBeReviewedBy($this->engineerUser, $measurement));

        // The admin grants the power to the engineer role in Approval Settings.
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin);
        Livewire::test(ManageApprovalSettings::class)
            ->set('data.approve_boq_measurements', [$this->engineerRole->id])
            ->call('save');

        $this->assertTrue($this->engineerUser->fresh()->hasSitePower('approve_boq_measurements'));

        // Even with the power, the engineer cannot approve their own entry.
        $this->expectException(ValidationException::class);
        $measurement->approve($this->engineerUser->fresh());
    }

    public function test_engineers_record_measurements_and_admins_approve_them_from_the_queue(): void
    {
        $item = $this->boqItem(45, 12000);

        Filament::setCurrentPanel(Filament::getPanel('team'));
        $this->actingAs($this->engineerUser);
        Livewire::test(ManageBoqMeasurements::class)
            ->callAction('create', ['project_id' => $this->project->id, 'boq_item_id' => $item->id, 'measured_date' => '2026-09-30', 'executed_quantity' => 30])
            ->assertHasNoActionErrors();

        $measurement = BoqMeasurement::sole();
        $this->assertSame('pending', $measurement->status);
        $this->assertSame($this->engineerUser->id, $measurement->entered_by);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin);
        Livewire::test(ManageBoqMeasurements::class)
            ->assertCanSeeTableRecords([$measurement])
            ->callTableAction('approve', $measurement);

        $this->assertSame('approved', $measurement->refresh()->status);
        $this->assertSame(66.7, $item->refresh()->progressPercent());
        $this->assertSame('Measurement approved: RCC in slab', $this->engineerUser->notifications()->latest('id')->first()->data['title']);
    }

    // ── BOQ library (§13.1) ───────────────────────────────────────────────────

    public function test_a_new_item_is_saved_to_the_project_and_the_library_and_keeps_its_own_rate(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin);

        Livewire::test(BoqItemsRelationManager::class, ['ownerRecord' => $this->project, 'pageClass' => EditProject::class])
            ->callTableAction('newItem', data: ['code' => 'RCC-01', 'description' => 'RCC M20 in slab', 'category' => 'concrete', 'unit' => 'm³', 'rate' => 14000, 'quantity' => 45])
            ->assertHasNoTableActionErrors();

        $master = BoqMasterItem::sole();
        $item = BoqItem::sole();
        $this->assertSame($master->id, $item->master_item_id);
        $this->assertSame('630000.00', $item->planned_value);

        // Changing the library rate only affects future projects.
        $master->update(['default_rate' => 15000]);
        $this->assertNotNull($master->rate_updated_at);
        $this->assertSame('14000.00', $item->refresh()->rate);

        // Used items cannot be deleted, only deactivated.
        $master->delete();
        $this->assertModelExists($master);
    }

    public function test_items_are_added_from_the_library_and_copied_from_another_project(): void
    {
        $master = BoqMasterItem::create(['code' => 'BW-01', 'description' => 'Brick work 1:6', 'unit' => 'm³', 'default_rate' => 11000]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin);

        Livewire::test(BoqItemsRelationManager::class, ['ownerRecord' => $this->project, 'pageClass' => EditProject::class])
            ->mountTableAction('addFromLibrary')
            ->setTableActionData(['master_item_id' => $master->id])
            ->assertTableActionDataSet(['rate' => '11000.00', 'unit' => 'm³'])
            ->setTableActionData(['quantity' => 20])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $other = $this->project->replicate(['progress']);
        $other->title = 'House – Itahari';
        $other->save();

        Livewire::test(BoqItemsRelationManager::class, ['ownerRecord' => $other, 'pageClass' => EditProject::class])
            ->callTableAction('copyFromProject', data: ['source_project_id' => $this->project->id])
            ->assertHasNoTableActionErrors();

        $this->assertSame(1, $other->boqItems()->count());
        $this->assertSame('220000.00', $other->boqItems()->first()->planned_value);
    }

    public function test_similar_library_items_are_detected_and_can_be_merged(): void
    {
        $keep = BoqMasterItem::create(['code' => 'PL-01', 'description' => 'Cement plaster 12mm 1:4', 'unit' => 'm²', 'default_rate' => 450]);
        $duplicate = BoqMasterItem::create(['description' => 'Cement plaster 12 mm (1:4)', 'unit' => 'm²', 'default_rate' => 460]);
        $line = $this->project->boqItems()->create(['master_item_id' => $duplicate->id, 'description' => $duplicate->description, 'unit' => 'm²', 'quantity' => 100, 'rate' => 460]);

        $this->assertTrue(BoqMasterItem::similarTo(null, 'cement plaster 12mm 1:4')->contains($duplicate));
        $this->assertTrue(BoqMasterItem::similarTo('pl-01', 'Something else')->contains($keep));

        $keep->absorb($duplicate);

        $this->assertSame($keep->id, $line->refresh()->master_item_id);
        $this->assertFalse($duplicate->refresh()->is_active);
    }

    // ── Ledger, budget and report (§3–§5) ─────────────────────────────────────

    public function test_approved_muster_rolls_post_labour_cost_and_returning_removes_it(): void
    {
        $labourer = Labourer::create(['name' => 'Ram', 'work_type' => 'mason', 'daily_wage' => 1500]);
        LabourAttendance::saveDay($this->project, '2026-09-20', [$labourer->id => ['status' => 'present']]);
        $roll = MusterRoll::create(['project_id' => $this->project->id, 'calendar' => 'bs', 'year' => 2083, 'month' => 6]);

        $roll->submit($this->admin);
        $this->assertSame(0, ProjectCost::count()); // submitted is not a cost yet

        $roll->approve($this->admin);
        $entry = ProjectCost::sole();
        $this->assertSame('labour', $entry->category);
        $this->assertSame('1500.00', $entry->amount);
        $this->assertSame(1500.0, $this->project->costReport()->costSoFar());

        $roll->returnForChanges($this->admin, 'Recount');
        $this->assertSame(0, ProjectCost::count());
    }

    public function test_projected_final_cost_does_not_double_count_committed_amounts(): void // check 5
    {
        $this->budget(['materials' => 800000, 'labour' => 350000, 'contingency' => 350000]); // 15 lakh
        $this->cost('materials', 600000);
        $this->cost('labour', 200000);

        $report = $this->project->costReport();

        $this->assertSame(1500000.0, $report->budgetTotal());
        $this->assertSame(300000.0, $report->plannedProfit());
        $this->assertSame(800000.0, $report->costSoFar());
        $this->assertSame(700000.0, $report->costToFinish()); // budget left, not added on top of committed
        $this->assertSame(1500000.0, $report->projectedFinalCost());
        $this->assertSame(300000.0, $report->projectedProfit());
        $this->assertSame(16.7, $report->projectedMargin());

        // An engineer's own estimate of what is left replaces the default.
        $this->project->update(['cost_to_finish_override' => 900000]);
        $report = $this->project->refresh()->costReport();
        $this->assertSame(1700000.0, $report->projectedFinalCost());
        $this->assertSame(100000.0, $report->projectedProfit());
    }

    public function test_categories_warn_at_80_percent_and_alert_over_100(): void // check 6
    {
        $this->budget(['materials' => 100000, 'labour' => 100000, 'transport' => 100000]);
        $this->cost('materials', 80000);
        $this->cost('labour', 104000);
        $this->cost('site_expenses', 5000);

        $flags = collect($this->project->costReport()->categories())->pluck('flag', 'key');

        $this->assertSame('warning', $flags['materials']);
        $this->assertSame('over', $flags['labour']);
        $this->assertSame('ok', $flags['transport']);
        $this->assertSame('unbudgeted', $flags['site_expenses']);
    }

    public function test_health_turns_red_when_spending_runs_more_than_10_points_ahead_of_progress(): void // check 8
    {
        $this->budget(['materials' => 1000000]);
        $this->project->update(['manual_progress' => 30]);

        $this->cost('materials', 320000); // 32% spent vs 30% done
        $this->assertSame('green', $this->project->refresh()->costReport()->health()['status']);

        $this->cost('materials', 50000); // 37% spent: 7 points ahead
        $this->assertSame('amber', $this->project->refresh()->costReport()->health()['status']);

        $this->cost('materials', 50000); // 42% spent: 12 points ahead
        $health = $this->project->refresh()->costReport()->health();
        $this->assertSame('red', $health['status']);
        $this->assertStringContainsString('12 points ahead', $health['reasons'][0]);
    }

    public function test_spending_in_an_unbudgeted_category_shows_budget_incomplete_instead_of_at_risk(): void
    {
        // A materials-only budget, with the money actually spent on labour.
        $this->budget(['materials' => 700]);
        $this->project->update(['manual_progress' => 100]);
        $this->cost('labour', 3200);

        $health = $this->project->refresh()->costReport()->health();
        $this->assertSame('incomplete', $health['status']);
        $this->assertStringContainsString('Labour has Rs 3,200.00 spent but no budget', $health['reasons'][0]);

        // Once labour has a budget, the normal comparison is back.
        $this->budget(['labour' => 5000]);
        $this->assertSame('green', $this->project->refresh()->costReport()->health()['status']);
    }

    public function test_boq_progress_is_preferred_over_the_manual_percentage(): void
    {
        $this->project->update(['manual_progress' => 90]);
        $item = $this->boqItem(10, 1000);
        $this->approvedMeasurement($item, 4);

        $work = $this->project->refresh()->costReport()->workComplete();

        $this->assertSame(40.0, $work['percent']);
        $this->assertSame('BOQ measurements', $work['source']);
    }

    public function test_the_cost_page_needs_the_view_costs_power_and_saves_the_budget(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin);
        Livewire::test(ProjectCosts::class, ['record' => $this->project->getRouteKey()])
            ->callAction('budget', ['budget_materials' => 800000, 'budget_labour' => 350000, 'manual_progress' => 25])
            ->assertHasNoActionErrors()
            ->assertSee('Rs 1,150,000.00');

        $this->assertSame(2, $this->project->budgets()->count());
        $this->assertSame(25, $this->project->refresh()->manual_progress);

        $this->get(route('site.projects.costs', $this->project))->assertOk()->assertSee('Project Cost Statement')->assertSee('VAT paid to suppliers (not claimable)');

        // Without the view-costs power the page and the printout are refused.
        $this->actingAs($this->engineerUser);
        $this->get(ProjectResource::getUrl('costs', ['record' => $this->project], panel: 'team'))->assertForbidden();
        $this->get(route('site.projects.costs', $this->project))->assertForbidden();
    }

    // ── VAT settings (§2) ─────────────────────────────────────────────────────

    public function test_vat_claimability_follows_the_registration_date(): void
    {
        $settings = CompanySetting::current();
        $this->assertFalse($settings->vatClaimable('vat', true, '2026-10-01')); // PAN-only

        $settings->update(['vat_registered' => true, 'vat_registration_date' => '2026-10-01']);

        $this->assertTrue($settings->vatClaimable('vat', true, '2026-10-01'));
        $this->assertFalse($settings->vatClaimable('vat', true, '2026-09-30'));  // before registration
        $this->assertFalse($settings->vatClaimable('pan', true, '2026-10-05'));  // PAN bill
        $this->assertFalse($settings->vatClaimable('vat', false, '2026-10-05')); // billed to someone else
    }

    public function test_pan_only_companies_put_no_vat_on_quotations(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin);

        Livewire::test(QuotationsRelationManager::class, ['ownerRecord' => $this->project, 'pageClass' => EditProject::class])
            ->mountTableAction('create')
            ->tap(function ($component): void {
                // Fill the repeater's first (empty) row, which the form starts with.
                $row = array_key_first($component->get('mountedTableActionsData.0.items'));
                $component->setTableActionData(["items.{$row}.description" => 'Design', "items.{$row}.rate" => 100000, 'vat_percent' => 13]);
            })
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $this->assertSame('0.00', $this->project->quotations()->sole()->vat_percent);
    }
}
