<?php

namespace Tests\Feature;

use App\Filament\Pages\ProjectsProfitability;
use App\Filament\Pages\TaxAndTurnover;
use App\Filament\Resources\VariationResource\Pages\ManageVariations;
use App\Models\Attendance;
use App\Models\AttendanceVisit;
use App\Models\Client;
use App\Models\ClientType;
use App\Models\CompanySetting;
use App\Models\OfficeLocation;
use App\Models\Payment;
use App\Models\Project;
use App\Models\ProjectCost;
use App\Models\ProjectType;
use App\Models\PurchaseBill;
use App\Models\StaffCostAllocation;
use App\Models\TaxReport;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\Variation;
use App\Models\Vendor;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class VariationsStaffCostAndTaxTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $clientUser;

    protected User $engineerUser;

    protected TeamMember $engineer;

    protected Project $project;

    protected Project $otherProject;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-20 06:00:00'); // 4 Mangsir 2083 → FY 2083/84

        $this->admin = User::factory()->create(['phone' => '9800000000']);
        $this->admin->assignRole(Role::create(['name' => 'super_admin']));

        $this->clientUser = User::factory()->create(['phone' => '9811111111']);
        $client = Client::create([
            'user_id' => $this->clientUser->id, 'contact_person' => 'Shishir Sharma',
            'client_type_id' => ClientType::create(['name' => 'Individual', 'slug' => 'individual'])->id,
            'contact' => '9811111111', 'address' => 'Belbari',
        ]);
        $type = ProjectType::create(['name' => 'Residential', 'slug' => 'residential', 'is_active' => true]);
        $this->project = Project::create(['client_id' => $client->id, 'project_type_id' => $type->id, 'title' => 'House – Belbari', 'description' => 'x', 'site_address' => 'Belbari', 'status' => 'execution', 'fee' => 1800000, 'created_by' => $this->admin->id]);
        $this->otherProject = Project::create(['client_id' => $client->id, 'project_type_id' => $type->id, 'title' => 'Shop – Itahari', 'description' => 'x', 'site_address' => 'Itahari', 'status' => 'execution', 'fee' => 500000, 'created_by' => $this->admin->id]);

        $this->engineerUser = User::factory()->create(['phone' => '9822222222']);
        $this->engineerUser->assignRole(Role::create(['name' => 'site_engineer']));
        $this->engineer = TeamMember::create([
            'user_id' => $this->engineerUser->id, 'fullname' => 'Hari Engineer', 'contact1' => '9822222222', 'marital_status' => 'Single',
            'national_id_path' => 'ids/id.jpg', 'bank_name' => 'Nabil', 'bank_account_name' => 'Hari', 'bank_account_number' => '1',
            'monthly_salary' => 60000, 'created_by' => $this->admin->id,
        ]);
        $this->project->teamMembers()->attach($this->engineer);
    }

    // ── Variations ────────────────────────────────────────────────────────────

    public function test_approved_extra_work_raises_the_contract_value_budget_and_client_balance(): void
    {
        $this->project->budgets()->create(['category' => 'materials', 'amount' => 1500000]);

        Filament::setCurrentPanel(Filament::getPanel('team'));
        $this->actingAs($this->engineerUser);
        Livewire::test(ManageVariations::class)
            ->callAction('create', ['project_id' => $this->project->id, 'title' => 'Terrace parapet', 'amount' => 120000, 'cost_budget' => 80000, 'budget_category' => 'materials', 'client_reference' => 'Signed letter'])
            ->assertHasNoActionErrors();

        $variation = Variation::sole();
        $this->assertFalse(Variation::canBeReviewedBy($this->engineerUser, $variation));
        $this->assertSame(1800000.0, $this->project->contractValue()); // pending doesn't count

        $variation->approve($this->admin);
        $report = $this->project->refresh()->costReport();

        $this->assertSame(1920000.0, $report->contractValue());
        $this->assertSame(120000.0, $report->variationsTotal());
        $this->assertSame(1580000.0, $report->budgetTotal());
        $this->assertSame(1920000.0, $this->project->balanceDue());
        $this->assertStringStartsWith('Extra work added', $this->clientUser->notifications()->sole()->data['title']);

        // The client can now pay up to the new total.
        $this->assertNull(Payment::amountError($this->project, 1900000));
    }

    // ── Staff cost ────────────────────────────────────────────────────────────

    protected function day(string $date, array $projects, bool $office = false): void
    {
        $attendance = Attendance::create(['team_member_id' => $this->engineer->id, 'date' => $date, 'first_seen_at' => "{$date} 03:00:00", 'last_seen_at' => "{$date} 11:00:00", 'source' => 'gps']);

        foreach ($projects as $project) {
            AttendanceVisit::create(['attendance_id' => $attendance->id, 'project_id' => $project->id, 'first_seen_at' => "{$date} 03:00:00", 'last_seen_at' => "{$date} 11:00:00", 'closest_distance' => 50]);
        }

        if ($office) {
            $officeId = OfficeLocation::firstOrCreate(['name' => 'Head office'], ['latitude' => 26.8, 'longitude' => 87.2, 'geofence_radius' => 200, 'is_active' => true])->id;
            AttendanceVisit::create(['attendance_id' => $attendance->id, 'office_location_id' => $officeId, 'first_seen_at' => "{$date} 03:00:00", 'last_seen_at' => "{$date} 11:00:00", 'closest_distance' => 20]);
        }
    }

    public function test_salary_is_shared_by_gps_days_split_days_count_half_and_office_days_stay_overhead(): void
    {
        // 10 days present in November: 6 at Belbari, 2 split between both sites, 2 at the office only.
        foreach (range(2, 7) as $d) {
            $this->day(sprintf('2026-11-%02d', $d), [$this->project]);
        }
        $this->day('2026-11-09', [$this->project, $this->otherProject]);
        $this->day('2026-11-10', [$this->project, $this->otherProject]);
        $this->day('2026-11-11', [], office: true);
        $this->day('2026-11-12', [], office: true);

        $this->artisan('costs:allocate-staff', ['month' => '2026-11-15'])->assertSuccessful();

        $belbari = StaffCostAllocation::where('project_id', $this->project->id)->sole();
        $this->assertSame('7.0', $belbari->project_days);  // 6 + ½ + ½
        $this->assertSame('42000.00', $belbari->amount);   // 60,000 × 7 ÷ 10
        $this->assertSame('6000.00', StaffCostAllocation::where('project_id', $this->otherProject->id)->value('amount')); // 1 day

        $report = $this->project->costReport();
        $this->assertSame(42000.0, $report->staffCost());
        $this->assertSame($report->projectedProfit() - 42000, $report->profitAfterStaffCost());

        // Re-running replaces, never duplicates.
        $this->artisan('costs:allocate-staff', ['month' => '2026-11-15'])->assertSuccessful();
        $this->assertSame(2, StaffCostAllocation::count());
    }

    public function test_staff_cost_lines_only_appear_when_there_is_staff_cost(): void
    {
        $this->actingAs($this->admin);
        $this->get(route('site.projects.costs', $this->project))->assertOk()->assertDontSee('Profit after staff cost');

        StaffCostAllocation::create(['project_id' => $this->project->id, 'team_member_id' => $this->engineer->id, 'month' => '2026-11-01', 'project_days' => 5, 'present_days' => 10, 'monthly_salary' => 60000, 'amount' => 30000]);

        $this->get(route('site.projects.costs', $this->project))->assertSee('Profit after staff cost');
    }

    // ── Turnover and VAT ──────────────────────────────────────────────────────

    public function test_fiscal_year_turnover_projection_and_limit_warning(): void
    {
        CompanySetting::current()->update(['vat_registration_limit' => 5000000, 'vat_warning_percent' => 80]);

        $this->assertSame(2083, TaxReport::fiscalYearOf());
        $report = new TaxReport(2083);
        $this->assertSame('FY 2083/84', $report->label());
        $this->assertSame('2026-07-17', $report->start->toDateString()); // 1 Shrawan 2083

        Payment::create(['project_id' => $this->project->id, 'amount' => 1000000, 'received_at' => '2026-10-01', 'recorded_by' => $this->admin->id]);
        Payment::create(['project_id' => $this->project->id, 'amount' => 500000, 'received_at' => '2026-06-01', 'recorded_by' => $this->admin->id]); // previous FY

        $report = new TaxReport(2083);
        $this->assertSame(1000000.0, $report->receipts());
        // 1,000,000 received + balances still owed (1,800,000 − 1,500,000 = 300,000; 500,000) = 1,800,000
        $this->assertSame(1800000.0, $report->projectedYearEnd());
        $this->assertSame(['actual' => 'ok', 'projected' => 'ok'], $report->limitStatus());

        CompanySetting::current()->update(['vat_registration_limit' => 2000000]);
        $this->assertSame(['actual' => 'ok', 'projected' => 'warning'], (new TaxReport(2083))->limitStatus()); // 1.8M ≥ 80% of 2M
    }

    public function test_vat_summary_nets_output_against_claimable_input_and_carries_credit_forward(): void
    {
        CompanySetting::current()->update(['vat_registered' => true, 'vat_registration_date' => '2026-10-18']); // 1 Kartik 2083
        $vendor = Vendor::create(['name' => 'Shree Hardware', 'vendor_type' => 'supplier', 'contact' => '1', 'created_by' => $this->admin->id]);

        // Kartik: large purchase, small receipt → credit
        PurchaseBill::create(['project_id' => $this->project->id, 'vendor_id' => $vendor->id, 'bill_type' => 'vat', 'bill_no' => 'K1', 'bill_date' => '2026-10-20', 'base_amount' => 100000, 'vat_amount' => 13000, 'entered_by' => $this->engineerUser->id])->approve($this->admin);
        Payment::create(['project_id' => $this->project->id, 'amount' => 56500, 'received_at' => '2026-10-25', 'recorded_by' => $this->admin->id]); // VAT inside: 6,500
        // Mangsir: receipt only
        Payment::create(['project_id' => $this->project->id, 'amount' => 113000, 'received_at' => '2026-11-18', 'recorded_by' => $this->admin->id]); // VAT inside: 13,000

        $rows = collect((new TaxReport(2083))->vatSummary())->keyBy('month');

        $this->assertSame(6500.0, $rows['Kartik 2083']['output']);
        $this->assertSame(13000.0, $rows['Kartik 2083']['input']);
        $this->assertSame(0.0, $rows['Kartik 2083']['payable']);
        $this->assertSame(6500.0, $rows['Kartik 2083']['credit']);
        $this->assertSame(6500.0, $rows['Mangsir 2083']['payable']); // 13,000 − 6,500 credit
        $this->assertSame(0.0, $rows['Mangsir 2083']['credit']);
        $this->assertArrayNotHasKey('Aswin 2083', $rows->all()); // before registration
    }

    public function test_profit_is_shown_both_ways_while_pan_only(): void
    {
        $vendor = Vendor::create(['name' => 'Shree Hardware', 'vendor_type' => 'supplier', 'contact' => '1', 'created_by' => $this->admin->id]);
        PurchaseBill::create(['project_id' => $this->project->id, 'vendor_id' => $vendor->id, 'bill_type' => 'vat', 'bill_no' => 'X1', 'bill_date' => '2026-11-01', 'base_amount' => 1000000, 'vat_amount' => 130000, 'entered_by' => $this->engineerUser->id])->approve($this->admin);

        $report = $this->project->costReport();
        $this->assertSame(670000.0, $report->projectedProfit()); // 1,800,000 − 1,130,000

        $ifVat = $report->profitIfVatRegistered();
        $this->assertSame(1592920.35, $ifVat['revenue']);  // 1,800,000 ÷ 1.13 (price includes VAT)
        $this->assertSame(1000000.0, $ifVat['cost']);      // supplier VAT claimable
        $this->assertSame(592920.35, $ifVat['profit']);

        $this->project->update(['price_basis' => 'plus_vat']);
        $this->assertSame(800000.0, $this->project->refresh()->costReport()->profitIfVatRegistered()['profit']);
    }

    public function test_dashboard_and_tax_pages_need_the_right_power(): void
    {
        ProjectCost::create(['project_id' => $this->project->id, 'category' => 'materials', 'source_type' => 'test', 'source_id' => 1, 'amount' => 2000000, 'entry_date' => '2026-11-01']);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin);
        Livewire::test(ProjectsProfitability::class)
            ->assertSee('House – Belbari')
            ->assertSee('1 projected to lose money');
        Livewire::test(TaxAndTurnover::class)
            ->assertSee('FY 2083/84')
            ->assertSee('PAN-only');

        Filament::setCurrentPanel(Filament::getPanel('team'));
        $this->actingAs($this->engineerUser);
        $this->assertFalse(ProjectsProfitability::canAccess());
        $this->assertFalse(TaxAndTurnover::canAccess());
    }
}
