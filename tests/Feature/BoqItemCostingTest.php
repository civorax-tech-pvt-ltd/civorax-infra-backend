<?php

namespace Tests\Feature;

use App\Filament\Pages\SiteMaterials;
use App\Filament\Resources\EquipmentEntryResource\Pages\ManageEquipmentEntries;
use App\Models\BoqItem;
use App\Models\Client;
use App\Models\ClientType;
use App\Models\EquipmentEntry;
use App\Models\KeyMaterial;
use App\Models\LabourAttendance;
use App\Models\Labourer;
use App\Models\MaterialIssue;
use App\Models\MusterRoll;
use App\Models\Project;
use App\Models\ProjectCost;
use App\Models\ProjectType;
use App\Models\PurchaseBill;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BoqItemCostingTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $supervisorUser;

    protected Project $project;

    protected BoqItem $slab;

    protected KeyMaterial $cement;

    protected Vendor $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-20 06:00:00');

        $this->admin = User::factory()->create(['phone' => '9800000000']);
        $this->admin->assignRole(Role::create(['name' => 'super_admin']));

        $client = Client::create([
            'user_id' => User::factory()->create(['phone' => '9811111111'])->id, 'contact_person' => 'Shishir Sharma',
            'client_type_id' => ClientType::create(['name' => 'Individual', 'slug' => 'individual'])->id,
            'contact' => '9811111111', 'address' => 'Belbari',
        ]);
        $this->project = Project::create([
            'client_id' => $client->id, 'project_type_id' => ProjectType::create(['name' => 'Residential', 'slug' => 'residential', 'is_active' => true])->id,
            'title' => 'House – Belbari', 'description' => 'x', 'site_address' => 'Belbari', 'status' => 'execution', 'fee' => 1800000,
            'track_item_costs' => true, 'created_by' => $this->admin->id,
        ]);

        $this->supervisorUser = User::factory()->create(['phone' => '9822222222']);
        $this->supervisorUser->assignRole(Role::create(['name' => 'site_supervisor']));
        $supervisor = TeamMember::create([
            'user_id' => $this->supervisorUser->id, 'fullname' => 'Hari Supervisor', 'contact1' => '9822222222', 'marital_status' => 'Single',
            'national_id_path' => 'ids/id.jpg', 'bank_name' => 'Nabil', 'bank_account_name' => 'Hari', 'bank_account_number' => '1',
            'created_by' => $this->admin->id,
        ]);
        $this->project->teamMembers()->attach($supervisor);

        $this->cement = KeyMaterial::where('name', 'Cement')->firstOrFail();
        // RCC slab: 40 m³ at Rs 15,000 = Rs 600,000 planned; 6.4 bags of cement per m³.
        $this->slab = $this->project->boqItems()->create(['code' => 'RCC-01', 'description' => 'RCC slab', 'unit' => 'm³', 'quantity' => 40, 'rate' => 15000, 'norms' => [['key_material_id' => $this->cement->id, 'per_unit' => 6.4]]]);

        // Cement bought at Rs 800 + 13% VAT on a VAT bill (PAN-only company → Rs 904 a bag).
        $this->supplier = Vendor::create(['name' => 'Shree Cement', 'vendor_type' => 'supplier', 'contact' => '1', 'created_by' => $this->admin->id]);
        PurchaseBill::create([
            'project_id' => $this->project->id, 'vendor_id' => $this->supplier->id, 'bill_type' => 'vat', 'bill_no' => 'C-1', 'bill_date' => '2026-11-01',
            'base_amount' => 400000, 'vat_amount' => 52000, 'entered_by' => $this->supervisorUser->id,
            'items' => [['key_material_id' => $this->cement->id, 'item' => 'Cement', 'quantity' => 500, 'unit' => 'bags', 'rate' => 800]],
        ])->approve($this->admin);
    }

    protected function measure(float $executed): void
    {
        $this->slab->measurements()->create(['measured_date' => '2026-11-18', 'executed_quantity' => $executed, 'status' => 'pending', 'entered_by' => $this->supervisorUser->id])->approve($this->admin);
        $this->slab->refresh();
    }

    protected function row(): array
    {
        return collect($this->project->refresh()->itemCostReport()->rows())->firstWhere('item.id', $this->slab->id);
    }

    public function test_direct_costs_carry_their_boq_tag_into_the_ledger(): void
    {
        $order = WorkOrder::create(['project_id' => $this->project->id, 'vendor_id' => $this->supplier->id, 'scope' => 'Shuttering', 'agreed_amount' => 100000, 'boq_item_id' => $this->slab->id, 'entered_by' => $this->supervisorUser->id]);
        $order->approve($this->admin);
        $bill = PurchaseBill::create(['work_order_id' => $order->id, 'bill_type' => 'pan', 'bill_no' => 'S-1', 'bill_date' => '2026-11-10', 'base_amount' => 40000, 'entered_by' => $this->supervisorUser->id]);
        $bill->approve($this->admin);
        $this->assertSame($this->slab->id, $bill->boq_item_id); // inherited from the work order

        EquipmentEntry::create(['project_id' => $this->project->id, 'boq_item_id' => $this->slab->id, 'kind' => 'equipment', 'description' => 'Mixer', 'entry_date' => '2026-11-12', 'unit' => 'day', 'quantity' => 2, 'rate' => 3000, 'entered_by' => $this->supervisorUser->id])->approve($this->admin);

        $labourer = Labourer::create(['name' => 'Ram', 'work_type' => 'mason', 'daily_wage' => 1500]);
        LabourAttendance::saveDay($this->project, '2026-11-12', [$labourer->id => ['status' => 'present']]);
        $roll = MusterRoll::create(['project_id' => $this->project->id, 'calendar' => 'bs', 'year' => 2083, 'month' => 7, 'boq_item_id' => $this->slab->id]);
        $roll->submit($this->admin);
        $roll->approve($this->admin);

        $this->assertSame(3, ProjectCost::where('boq_item_id', $this->slab->id)->count());
        $this->assertSame(40000.0 + 6000 + 1500, $this->row()['direct']);
        // The cement bill is shared material: untagged, still in the project total.
        $this->assertSame(452000.0, $this->project->itemCostReport()->untaggedCost());
    }

    public function test_norms_estimate_material_until_an_issue_is_recorded(): void
    {
        $this->measure(10); // 25% done → 64 bags by norm

        $row = $this->row();
        $this->assertSame(150000.0, $row['earned']);         // 10 × 15,000
        $this->assertSame(57856.0, $row['estimated']);       // 64 × 904
        $this->assertSame(0.0, (float) $row['coverage']);    // all estimated
        $this->assertSame(['Cement 64'], $row['estimates']);

        // Supervisor records the real issue; after approval it replaces the estimate.
        $issue = MaterialIssue::create(['project_id' => $this->project->id, 'boq_item_id' => $this->slab->id, 'key_material_id' => $this->cement->id, 'issued_on' => '2026-11-15', 'quantity' => 70, 'issued_by' => $this->supervisorUser->id]);
        $this->assertSame('904.00', $issue->rate);
        $this->assertSame('63280.00', $issue->value);
        $this->assertSame(57856.0, $this->row()['estimated']); // pending issues don't count yet

        try {
            $issue->approve($this->supervisorUser);
            $this->fail('Approved own material issue.');
        } catch (ValidationException) {
        }

        $issue->approve($this->admin);
        $row = $this->row();
        $this->assertSame(0.0, $row['estimated']);
        $this->assertSame(63280.0, $row['issued']);
        $this->assertSame(100.0, (float) $row['coverage']);

        // Issues only split existing cost between items: project cost is unchanged.
        $this->assertSame(452000.0, $this->project->costReport()->costSoFar());
    }

    public function test_item_variance_projection_and_status(): void
    {
        $this->measure(10); // earned 150,000
        EquipmentEntry::create(['project_id' => $this->project->id, 'boq_item_id' => $this->slab->id, 'kind' => 'equipment', 'description' => 'Pump & labour', 'entry_date' => '2026-11-12', 'unit' => 'lump_sum', 'quantity' => 1, 'rate' => 100000, 'entered_by' => $this->supervisorUser->id])->approve($this->admin);

        $row = $this->row(); // actual = 100,000 + 57,856 estimated
        $this->assertSame(157856.0, $row['actual']);
        $this->assertSame(-7856.0, $row['variance']);
        $this->assertSame(631424.0, $row['projected']); // 157,856 ÷ 25%
        $this->assertSame('amber', $row['status']);      // within 10% over earned
        $this->assertSame(63.0, (float) $row['coverage']);
    }

    public function test_tag_fields_and_the_item_report_only_show_when_the_project_tracks_items(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('team'));
        $this->actingAs($this->supervisorUser);

        Livewire::test(ManageEquipmentEntries::class)
            ->mountAction('create')
            ->setActionData(['project_id' => $this->project->id])
            ->assertFormFieldIsVisible('boq_item_id', 'mountedActionForm');

        $this->project->update(['track_item_costs' => false]);
        Livewire::test(ManageEquipmentEntries::class)
            ->mountAction('create')
            ->setActionData(['project_id' => $this->project->id])
            ->assertFormFieldIsHidden('boq_item_id', 'mountedActionForm');

        $this->actingAs($this->admin);
        $this->get(route('site.projects.costs', $this->project))->assertDontSee('Cost per BOQ item');
        $this->project->update(['track_item_costs' => true]);
        $this->get(route('site.projects.costs', $this->project))->assertSee('Cost per BOQ item')->assertSee('RCC-01');
    }

    public function test_material_is_issued_from_the_site_materials_page(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('team'));
        $this->actingAs($this->supervisorUser);

        Livewire::withQueryParams(['project' => $this->project->id])
            ->test(SiteMaterials::class)
            ->callAction('issue', ['boq_item_id' => $this->slab->id, 'key_material_id' => $this->cement->id, 'quantity' => 30, 'issued_on' => '2026-11-19'])
            ->assertHasNoActionErrors()
            ->assertSee('Material issued to BOQ items');

        $this->assertSame('27120.00', MaterialIssue::sole()->value); // 30 × 904
    }
}
