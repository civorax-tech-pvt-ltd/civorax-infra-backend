<?php

namespace Tests\Feature;

use App\Filament\Pages\SiteMaterials;
use App\Filament\Resources\EquipmentEntryResource\Pages\ManageEquipmentEntries;
use App\Filament\Resources\PurchaseBillResource\Pages\CreatePurchaseBill;
use App\Filament\Resources\WorkOrderResource\Pages\ManageWorkOrders;
use App\Models\Client;
use App\Models\ClientType;
use App\Models\EquipmentEntry;
use App\Models\KeyMaterial;
use App\Models\MaterialDelivery;
use App\Models\MaterialStockCount;
use App\Models\MaterialTransfer;
use App\Models\Project;
use App\Models\ProjectCost;
use App\Models\ProjectMaterialPlan;
use App\Models\ProjectType;
use App\Models\PurchaseBill;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WorkOrdersEquipmentAndMaterialsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $supervisorUser;

    protected Project $project;

    protected Project $otherProject;

    protected Vendor $plumber;

    protected KeyMaterial $cement;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-05 06:00:00');

        $this->admin = User::factory()->create(['phone' => '9800000000']);
        $this->admin->assignRole(Role::create(['name' => 'super_admin']));

        $client = Client::create([
            'user_id' => User::factory()->create(['phone' => '9811111111'])->id,
            'contact_person' => 'Shishir Sharma',
            'client_type_id' => ClientType::create(['name' => 'Individual', 'slug' => 'individual'])->id,
            'contact' => '9811111111',
            'address' => 'Belbari',
        ]);
        $type = ProjectType::create(['name' => 'Residential', 'slug' => 'residential', 'is_active' => true]);
        $this->project = Project::create(['client_id' => $client->id, 'project_type_id' => $type->id, 'title' => 'House – Belbari', 'description' => 'x', 'site_address' => 'Belbari', 'status' => 'execution', 'fee' => 1800000, 'created_by' => $this->admin->id]);
        $this->otherProject = Project::create(['client_id' => $client->id, 'project_type_id' => $type->id, 'title' => 'Shop – Itahari', 'description' => 'x', 'site_address' => 'Itahari', 'status' => 'execution', 'fee' => 900000, 'created_by' => $this->admin->id]);

        $this->supervisorUser = User::factory()->create(['phone' => '9822222222']);
        $this->supervisorUser->assignRole(Role::create(['name' => 'site_supervisor']));
        $supervisor = TeamMember::create([
            'user_id' => $this->supervisorUser->id, 'fullname' => 'Hari Supervisor', 'contact1' => '9822222222', 'marital_status' => 'Single',
            'national_id_path' => 'ids/id.jpg', 'bank_name' => 'Nabil', 'bank_account_name' => 'Hari', 'bank_account_number' => '1',
            'created_by' => $this->admin->id,
        ]);
        $this->project->teamMembers()->attach($supervisor);
        $this->otherProject->teamMembers()->attach($supervisor);

        $this->plumber = Vendor::create(['name' => 'Ram Plumbing', 'vendor_type' => 'subcontractor', 'contact' => '9855555555', 'created_by' => $this->admin->id]);
        $this->cement = KeyMaterial::where('name', 'Cement')->firstOrFail(); // seeded by the migration
    }

    protected function workOrder(float $agreed = 150000): WorkOrder
    {
        $order = WorkOrder::create(['project_id' => $this->project->id, 'vendor_id' => $this->plumber->id, 'scope' => 'Plumbing', 'agreed_amount' => $agreed, 'entered_by' => $this->supervisorUser->id]);
        $order->approve($this->admin);

        return $order;
    }

    protected function subcontractBill(WorkOrder $order, float $amount): PurchaseBill
    {
        $bill = PurchaseBill::create(['work_order_id' => $order->id, 'bill_type' => 'pan', 'bill_no' => 'RB-'.fake()->unique()->numberBetween(1, 999), 'bill_date' => '2026-11-01', 'base_amount' => $amount, 'entered_by' => $this->supervisorUser->id]);
        $bill->approve($this->admin);

        return $bill;
    }

    public function test_work_orders_number_themselves_and_need_approval_by_someone_else(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('team'));
        $this->actingAs($this->supervisorUser);
        Livewire::test(ManageWorkOrders::class)
            ->callAction('create', ['project_id' => $this->project->id, 'vendor_id' => $this->plumber->id, 'scope' => 'Plumbing, both floors', 'agreed_amount' => 150000])
            ->assertHasNoActionErrors();

        $order = WorkOrder::sole();
        $this->assertSame('WO-2026-001', $order->number);
        $this->assertSame('pending', $order->status);
        $this->assertFalse(WorkOrder::canBeReviewedBy($this->supervisorUser, $order));
        $this->assertSame(0.0, $this->project->costReport()->committed()); // not approved yet

        $order->approve($this->admin);
        $this->assertSame(150000.0, $this->project->costReport()->committed());
    }

    public function test_committed_shrinks_as_bills_arrive_so_nothing_is_counted_twice(): void // check 5 with real commitments
    {
        $this->project->budgets()->create(['category' => 'subcontract', 'amount' => 200000]);
        $order = $this->workOrder(150000);

        $this->subcontractBill($order, 60000);

        $report = $this->project->refresh()->costReport();
        $this->assertSame(60000.0, $report->costSoFar());
        $this->assertSame(90000.0, $report->committed());
        $this->assertSame(50000.0, $report->costToFinish()); // 200,000 − 60,000 − 90,000
        $this->assertSame(200000.0, $report->projectedFinalCost());

        $this->assertSame('subcontract', ProjectCost::sole()->category);
        $this->assertSame($this->plumber->id, PurchaseBill::sole()->vendor_id);

        // Closing the order releases what was never billed.
        $order->close();
        $this->assertSame(0.0, $this->project->refresh()->costReport()->committed());
    }

    public function test_a_bill_cannot_exceed_what_is_left_on_the_work_order(): void
    {
        Storage::fake('public');
        $order = $this->workOrder(150000);
        $this->subcontractBill($order, 100000);

        Filament::setCurrentPanel(Filament::getPanel('team'));
        $this->actingAs($this->supervisorUser);

        Livewire::withQueryParams(['work_order' => $order->id])
            ->test(CreatePurchaseBill::class)
            ->assertFormSet(['work_order_id' => $order->id, 'vendor_id' => $this->plumber->id, 'category' => 'subcontract'])
            ->fillForm(['bill_type' => 'pan', 'bill_no' => 'RB-9', 'bill_date' => '2026-11-04', 'base_amount' => 60000, 'photos' => [UploadedFile::fake()->image('bill.jpg')]])
            ->call('create')
            ->assertHasFormErrors(['base_amount']);

        Livewire::withQueryParams(['work_order' => $order->id])
            ->test(CreatePurchaseBill::class)
            ->fillForm(['bill_type' => 'pan', 'bill_no' => 'RB-9', 'bill_date' => '2026-11-04', 'base_amount' => 50000, 'photos' => [UploadedFile::fake()->image('bill.jpg')]])
            ->call('create')
            ->assertHasNoFormErrors();
    }

    public function test_equipment_entries_post_cost_and_count_as_billed_by_the_provider(): void
    {
        $jcb = Vendor::create(['name' => 'Koshi Earthmovers', 'vendor_type' => 'supplier', 'contact' => '9866666666', 'created_by' => $this->admin->id]);

        $entry = EquipmentEntry::create(['project_id' => $this->project->id, 'vendor_id' => $jcb->id, 'kind' => 'equipment', 'description' => 'JCB footing excavation', 'entry_date' => '2026-11-03', 'unit' => 'hour', 'quantity' => 6, 'rate' => 3500, 'entered_by' => $this->supervisorUser->id]);
        $trip = EquipmentEntry::create(['project_id' => $this->project->id, 'kind' => 'transport', 'description' => 'Tipper sand', 'entry_date' => '2026-11-03', 'unit' => 'trip', 'quantity' => 4, 'rate' => 2500, 'entered_by' => $this->supervisorUser->id]);

        $this->assertSame('21000.00', $entry->amount);
        $this->assertFalse(EquipmentEntry::canBeReviewedBy($this->supervisorUser, $entry));
        $this->assertSame(0, ProjectCost::count());

        $entry->approve($this->admin);
        $trip->approve($this->admin);

        $categories = collect($this->project->costReport()->categories())->pluck('actual', 'key');
        $this->assertSame(21000.0, $categories['equipment']);
        $this->assertSame(10000.0, $categories['transport']);

        $this->assertSame(21000.0, $jcb->outstanding());
        $this->assertStringContainsString('JCB footing excavation', $jcb->statement()['rows'][0]['entry']);
    }

    public function test_supervisors_log_trips_from_the_equipment_screen(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('team'));
        $this->actingAs($this->supervisorUser);

        Livewire::test(ManageEquipmentEntries::class)
            ->callAction('create', ['project_id' => $this->project->id, 'kind' => 'transport', 'entry_date' => '2026-11-04', 'description' => 'Tipper aggregate', 'quantity' => 3, 'unit' => 'trip', 'rate' => 2800])
            ->assertHasNoActionErrors()
            ->assertSee('Tipper aggregate');

        $this->assertSame('8400.00', EquipmentEntry::sole()->amount);
        $this->assertSame('pending', EquipmentEntry::sole()->status);
    }

    public function test_material_usage_compares_purchased_received_and_expected_use(): void
    {
        $this->project->update(['manual_progress' => 60]);
        ProjectMaterialPlan::create(['project_id' => $this->project->id, 'key_material_id' => $this->cement->id, 'planned_quantity' => 1000]);

        $supplier = Vendor::create(['name' => 'Shree Cement', 'vendor_type' => 'supplier', 'contact' => '9877777777', 'created_by' => $this->admin->id]);
        $bill = PurchaseBill::create([
            'project_id' => $this->project->id, 'vendor_id' => $supplier->id, 'bill_type' => 'pan', 'bill_no' => 'C-1', 'bill_date' => '2026-10-20',
            'base_amount' => 1120000, 'entered_by' => $this->supervisorUser->id,
            'items' => [['key_material_id' => $this->cement->id, 'item' => 'Cement', 'quantity' => 1400, 'unit' => 'bags', 'rate' => 800]],
        ]);
        $bill->approve($this->admin);

        MaterialDelivery::create(['project_id' => $this->project->id, 'key_material_id' => $this->cement->id, 'delivered_on' => '2026-10-21', 'quantity' => 1400, 'entered_by' => $this->supervisorUser->id]);
        MaterialStockCount::create(['project_id' => $this->project->id, 'key_material_id' => $this->cement->id, 'counted_on' => '2026-11-01', 'quantity_left' => 500, 'entered_by' => $this->supervisorUser->id]);

        $row = collect($this->project->materialReport()->rows())->firstWhere('material.id', $this->cement->id);

        $this->assertSame(1000.0, $row['planned']);
        $this->assertSame(1400.0, $row['purchased']);
        $this->assertSame(1400.0, $row['received']);
        $this->assertSame(900.0, $row['used']);          // received − left
        $this->assertSame(600.0, $row['expected_use']);  // planned × 60%
        $this->assertSame('Purchased 1,400 bags, planned 1,000, work 60% done', $row['flag']);
    }

    public function test_transfers_move_cost_between_projects_and_returns_credit_the_project(): void
    {
        $supplier = Vendor::create(['name' => 'Shree Cement', 'vendor_type' => 'supplier', 'contact' => '9877777777', 'created_by' => $this->admin->id]);
        PurchaseBill::create([
            'project_id' => $this->project->id, 'vendor_id' => $supplier->id, 'bill_type' => 'vat', 'bill_no' => 'C-2', 'bill_date' => '2026-10-20',
            'base_amount' => 80000, 'vat_amount' => 10400, 'entered_by' => $this->supervisorUser->id,
            'items' => [['key_material_id' => $this->cement->id, 'item' => 'Cement', 'quantity' => 100, 'unit' => 'bags', 'rate' => 800]],
        ])->approve($this->admin);

        // PAN-only: the average rate includes the supplier VAT (800 × 1.13).
        $this->assertSame(904.0, $this->project->materialReport()->averageRate($this->cement->id));

        MaterialTransfer::create(['from_project_id' => $this->project->id, 'to_project_id' => $this->otherProject->id, 'key_material_id' => $this->cement->id, 'transferred_on' => '2026-11-02', 'quantity' => 20, 'value' => 18080, 'entered_by' => $this->supervisorUser->id]);
        MaterialTransfer::create(['from_project_id' => $this->project->id, 'to_project_id' => null, 'vendor_id' => $supplier->id, 'key_material_id' => $this->cement->id, 'transferred_on' => '2026-11-03', 'quantity' => 10, 'value' => 9040, 'entered_by' => $this->supervisorUser->id]);

        $this->assertSame(90400.0 - 18080 - 9040, $this->project->costReport()->costSoFar());
        $this->assertSame(18080.0, $this->otherProject->costReport()->costSoFar());

        $row = collect($this->project->materialReport()->rows())->firstWhere('material.id', $this->cement->id);
        $this->assertSame(30.0, $row['transferred_out']);
    }

    public function test_the_site_materials_page_records_deliveries_and_counts(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('team'));
        $this->actingAs($this->supervisorUser);

        Livewire::withQueryParams(['project' => $this->project->id])
            ->test(SiteMaterials::class)
            ->callAction('delivery', ['key_material_id' => $this->cement->id, 'quantity' => 200, 'delivered_on' => '2026-11-04', 'challan_no' => 'CH-77'])
            ->assertHasNoActionErrors()
            ->assertSee('CH-77');

        $this->assertSame('200.00', MaterialDelivery::sole()->quantity);

        $this->actingAs($this->admin); // the cron runs as nobody; don't let the supervisor be the "actor"
        $this->artisan('materials:stock-count-reminders')->assertSuccessful();
        $this->assertSame('Monthly stock count due', $this->supervisorUser->notifications()->sole()->data['title']);
    }
}
