<?php

namespace Tests\Feature;

use App\Filament\Resources\PettyCashClaimResource\Pages\ManagePettyCashClaims;
use App\Filament\Resources\PurchaseBillResource\Pages\CreatePurchaseBill;
use App\Filament\Resources\PurchaseBillResource\Pages\ListPurchaseBills;
use App\Filament\Resources\VendorResource\Pages\VendorStatement;
use App\Models\Client;
use App\Models\ClientType;
use App\Models\CompanySetting;
use App\Models\PettyCashClaim;
use App\Models\Project;
use App\Models\ProjectCost;
use App\Models\ProjectType;
use App\Models\PurchaseBill;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorPayment;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchaseBillsAndPettyCashTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $supervisorUser;

    protected User $accountantUser;

    protected Project $project;

    protected Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-10 06:00:00');

        $superAdmin = Role::create(['name' => 'super_admin']);
        foreach (['view_any_vendor', 'view_vendor', 'update_vendor'] as $permission) {
            $superAdmin->givePermissionTo(Permission::create(['name' => $permission]));
        }
        $this->admin = User::factory()->create(['phone' => '9800000000']);
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

        $supervisorRole = Role::create(['name' => 'site_supervisor']);
        $this->supervisorUser = User::factory()->create(['phone' => '9822222222']);
        $this->supervisorUser->assignRole($supervisorRole);
        $supervisor = TeamMember::create([
            'user_id' => $this->supervisorUser->id, 'fullname' => 'Hari Supervisor', 'contact1' => '9822222222', 'marital_status' => 'Single',
            'national_id_path' => 'ids/id.jpg', 'bank_name' => 'Nabil', 'bank_account_name' => 'Hari', 'bank_account_number' => '1',
            'created_by' => $this->admin->id,
        ]);
        $this->project->teamMembers()->attach($supervisor);

        $this->accountantUser = User::factory()->create(['phone' => '9833333333']);
        $this->accountantUser->assignRole(Role::create(['name' => 'accountant'])->givePermissionTo(['approve_purchase_bills', 'pay_vendors', 'view_vendor_ledger']));

        $this->vendor = Vendor::create(['name' => 'Shree Hardware', 'vendor_type' => 'supplier', 'contact' => '9844444444', 'pan_vat_no' => '601234567', 'created_by' => $this->admin->id]);
    }

    protected function bill(array $overrides = []): PurchaseBill
    {
        return PurchaseBill::create([
            'project_id' => $this->project->id,
            'vendor_id' => $this->vendor->id,
            'bill_type' => 'vat',
            'bill_no' => 'B-'.fake()->unique()->numberBetween(100, 999),
            'bill_date' => '2026-10-05',
            'base_amount' => 1000,
            'vat_amount' => 130,
            'status' => 'pending',
            'entered_by' => $this->supervisorUser->id,
            ...$overrides,
        ]);
    }

    public function test_check_1_pan_only_a_vat_bill_costs_its_full_amount_and_shows_unclaimed_vat(): void
    {
        $bill = $this->bill();
        $bill->approve($this->accountantUser);

        $this->assertFalse($bill->vat_claimable);
        $entry = ProjectCost::sole();
        $this->assertSame('1130.00', $entry->amount);
        $this->assertSame('130.00', $entry->vat_not_claimable);

        $report = $this->project->costReport();
        $this->assertSame(1130.0, $report->costSoFar());
        $this->assertSame(130.0, $report->vatNotClaimable());
    }

    public function test_check_2_after_registration_only_bills_dated_on_or_after_it_exclude_vat(): void
    {
        CompanySetting::current()->update(['vat_registered' => true, 'vat_registration_date' => '2026-10-01']);

        $after = $this->bill(['bill_date' => '2026-10-05']);
        $before = $this->bill(['bill_date' => '2026-09-20']);
        $after->approve($this->accountantUser);
        $before->approve($this->accountantUser);

        $this->assertTrue($after->vat_claimable);
        $this->assertFalse($before->vat_claimable);
        $this->assertSame(1000.0, (float) ProjectCost::where('source_id', $after->id)->value('amount'));
        $this->assertSame(1130.0, (float) ProjectCost::where('source_id', $before->id)->value('amount'));
        $this->assertSame(130.0, $this->project->costReport()->vatNotClaimable());
    }

    public function test_check_3_the_vendor_is_owed_the_bill_total_in_both_modes(): void
    {
        $this->bill()->approve($this->accountantUser);
        $this->assertSame(1130.0, $this->vendor->outstanding());

        CompanySetting::current()->update(['vat_registered' => true, 'vat_registration_date' => '2026-10-01']);
        $this->bill()->approve($this->accountantUser);

        $this->assertSame(2260.0, $this->vendor->outstanding());

        VendorPayment::create(['vendor_id' => $this->vendor->id, 'purchase_bill_id' => PurchaseBill::first()->id, 'amount' => 1130, 'paid_on' => '2026-10-08']);
        $this->assertSame(1130.0, $this->vendor->outstanding());
        $this->assertSame('Paid', PurchaseBill::first()->paidStatus());
        $this->assertSame(1130.0, $this->project->costReport()->paidOut()); // cash paid out for the project
    }

    public function test_check_4_a_bill_made_out_to_another_company_is_flagged_and_kept_out_until_a_super_admin_accepts_it(): void
    {
        $bill = $this->bill(['billed_to_company' => false]);

        $this->assertTrue($bill->isFlagged());
        $this->assertSame(0, ProjectCost::count());
        $this->assertStringStartsWith('Bill to review (not billed to the company)', $this->admin->notifications()->sole()->data['title']);

        // An ordinary approver cannot accept it.
        $this->assertFalse(PurchaseBill::canBeReviewedBy($this->accountantUser, $bill));

        try {
            $bill->approve($this->accountantUser);
            $this->fail('Accepted a flagged bill without super admin review.');
        } catch (ValidationException) {
            $this->assertSame(0, ProjectCost::count());
        }

        $bill->approve($this->admin);
        $this->assertSame(1, ProjectCost::count());
    }

    public function test_supervisors_enter_bills_with_a_photo_and_duplicates_are_refused(): void
    {
        Storage::fake('public');
        Filament::setCurrentPanel(Filament::getPanel('team'));
        $this->actingAs($this->supervisorUser);

        $fill = ['project_id' => $this->project->id, 'vendor_id' => $this->vendor->id, 'bill_type' => 'vat', 'bill_no' => 'INV-101', 'bill_date' => '2026-10-05', 'category' => 'materials', 'base_amount' => 50000, 'photos' => [UploadedFile::fake()->image('bill.jpg')]];

        Livewire::test(CreatePurchaseBill::class)
            ->fillForm($fill)
            ->assertFormSet(['vat_amount' => 6500.0]) // filled at 13%
            ->call('create')
            ->assertHasNoFormErrors();

        $bill = PurchaseBill::sole();
        $this->assertSame('56500.00', $bill->total_amount);
        $this->assertSame('601234567', $bill->vendor_pan_vat);
        $this->assertSame($this->supervisorUser->id, $bill->entered_by);

        Livewire::test(CreatePurchaseBill::class)
            ->fillForm([...$fill, 'bill_no' => ' inv-101 '])
            ->call('create')
            ->assertHasFormErrors(['bill_no']);
    }

    public function test_nobody_approves_their_own_bill_and_reopening_removes_the_cost(): void
    {
        $this->accountantUser->assignRole(Role::findByName('site_supervisor'));
        $own = $this->bill(['entered_by' => $this->accountantUser->id]);
        $this->assertFalse(PurchaseBill::canBeReviewedBy($this->accountantUser, $own));

        $bill = $this->bill();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin);

        Livewire::test(ListPurchaseBills::class)
            ->callTableAction('approve', $bill);
        $this->assertSame('approved', $bill->refresh()->status);
        $this->assertSame(1, ProjectCost::count());

        Livewire::test(ListPurchaseBills::class)
            ->callTableAction('reopen', $bill);
        $this->assertSame('pending', $bill->refresh()->status);
        $this->assertSame(0, ProjectCost::count());
    }

    public function test_petty_cash_claims_are_approved_into_cost_and_reimbursed_as_cash_out(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('team'));
        $this->actingAs($this->supervisorUser);
        Livewire::test(ManagePettyCashClaims::class)
            ->callAction('create', ['project_id' => $this->project->id, 'expense_date' => '2026-10-09', 'description' => 'Tea for labour', 'amount' => 850, 'category' => 'site_expenses'])
            ->assertHasNoActionErrors();

        $claim = PettyCashClaim::sole();
        $this->assertSame($this->supervisorUser->id, $claim->claimed_by);
        $this->assertFalse(PettyCashClaim::canBeReviewedBy($this->supervisorUser, $claim));

        $claim->approve($this->admin);
        $this->assertSame(850.0, $this->project->costReport()->costSoFar());
        $this->assertSame(0.0, $this->project->costReport()->vatNotClaimable());
        $this->assertSame(0.0, $this->project->costReport()->paidOut());

        $claim->markReimbursed($this->accountantUser, '2026-10-10');
        $this->assertSame(850.0, $this->project->costReport()->paidOut());
    }

    public function test_vendor_statement_shows_billed_paid_and_records_confirmations(): void
    {
        $bill = $this->bill();
        $bill->approve($this->accountantUser);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin);

        Livewire::test(VendorStatement::class, ['record' => $this->vendor->getRouteKey()])
            ->assertSee('1,130.00')
            ->callAction('pay', ['purchase_bill_id' => $bill->id, 'amount' => 500, 'paid_on' => '2026-10-09', 'method' => 'cash'])
            ->assertHasNoActionErrors()
            ->callAction('confirm', ['as_of' => '2026-10-10', 'balance' => 630, 'agreed' => true])
            ->assertHasNoActionErrors();

        $this->assertSame(630.0, $this->vendor->outstanding());
        $this->assertSame('Part paid', $bill->refresh()->paidStatus());
        $this->assertSame(1, $this->vendor->balanceConfirmations()->count());

        $statement = $this->vendor->statement();
        $this->assertSame([1130.0, 630.0], array_column($statement['rows'], 'balance'));

        $this->get(route('site.vendors.statement', $this->vendor))->assertOk()->assertSee('Statement of Account');
        $this->actingAs($this->supervisorUser)->get(route('site.vendors.statement', $this->vendor))->assertForbidden();
    }
}
