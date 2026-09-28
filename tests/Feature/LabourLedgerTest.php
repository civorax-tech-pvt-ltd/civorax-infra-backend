<?php

namespace Tests\Feature;

use App\Filament\Resources\LabourContractorResource\Pages\NaikeAccount;
use App\Filament\Resources\LabourerResource;
use App\Filament\Resources\LabourerResource\Pages\LabourerLedger;
use App\Filament\Resources\LabourerResource\Pages\ManageLabourers;
use App\Models\Client;
use App\Models\ClientType;
use App\Models\LabourAttendance;
use App\Models\LabourContractor;
use App\Models\Labourer;
use App\Models\MusterRoll;
use App\Models\Project;
use App\Models\ProjectType;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\WagePayment;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LabourLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $supervisorUser;

    protected Role $teamRole;

    protected Project $siteA;

    protected Project $siteB;

    protected Labourer $mason;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-20 06:00:00');

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
        $this->siteA = Project::create(['client_id' => $client->id, 'project_type_id' => $type->id, 'title' => 'Site A', 'description' => 'x', 'site_address' => 'Belbari', 'status' => 'execution', 'created_by' => $this->admin->id]);
        $this->siteB = Project::create(['client_id' => $client->id, 'project_type_id' => $type->id, 'title' => 'Site B', 'description' => 'x', 'site_address' => 'Itahari', 'status' => 'execution', 'created_by' => $this->admin->id]);

        $this->teamRole = Role::create(['name' => 'team_member']);
        $this->supervisorUser = User::factory()->create(['phone' => '9822222222']);
        $this->supervisorUser->assignRole($this->teamRole);
        TeamMember::create([
            'user_id' => $this->supervisorUser->id, 'fullname' => 'Hari Supervisor', 'contact1' => '9822222222', 'marital_status' => 'Single',
            'national_id_path' => 'ids/id.jpg', 'bank_name' => 'Nabil', 'bank_account_name' => 'Hari', 'bank_account_number' => '1',
            'created_by' => $this->admin->id,
        ]);

        $naike = LabourContractor::create(['name' => 'Ram Naike']);
        $this->mason = Labourer::create(['name' => 'Ram Bahadur', 'work_type' => 'mason', 'daily_wage' => 1000, 'labour_contractor_id' => $naike->id]);
    }

    protected function approvedMonth(Project $site, string $date, int $month): MusterRoll
    {
        LabourAttendance::saveDay($site, $date, [$this->mason->id => ['status' => 'present']]);
        $roll = MusterRoll::create(['project_id' => $site->id, 'calendar' => 'bs', 'year' => 2083, 'month' => $month]);
        $roll->submit($this->admin);
        $roll->approve($this->admin);

        return $roll;
    }

    public function test_an_advance_at_one_site_is_recovered_from_wages_at_another(): void
    {
        WagePayment::create(['project_id' => $this->siteA->id, 'labourer_id' => $this->mason->id, 'type' => 'advance', 'amount' => 600, 'paid_on' => '2026-10-01']);

        $rollB = $this->approvedMonth($this->siteB, '2026-10-20', 7); // Kartik 2083 at Site B

        $this->assertSame(400.0, $this->mason->balance());
        $arrears = $rollB->arrears();
        $this->assertSame(600.0, $arrears[0]['paid']);
        $this->assertSame(400.0, $arrears[0]['unpaid']);
    }

    public function test_returned_advances_reduce_the_advance_and_the_ledger_runs_in_date_order(): void
    {
        WagePayment::create(['project_id' => $this->siteA->id, 'labourer_id' => $this->mason->id, 'type' => 'advance', 'amount' => 3000, 'paid_on' => '2026-09-20']);
        WagePayment::create(['project_id' => $this->siteA->id, 'labourer_id' => $this->mason->id, 'type' => 'recovery', 'amount' => 500, 'paid_on' => '2026-09-25']);
        $this->approvedMonth($this->siteA, '2026-09-22', 6); // Aswin, ends Oct 17

        $this->assertSame(-500.0, (float) WagePayment::where('type', 'recovery')->value('amount'));
        $this->assertSame(-1500.0, $this->mason->balance()); // 1000 earned + 500 returned − 3000 given

        $ledger = $this->mason->ledger();
        $this->assertSame(['Advance', 'Advance returned', 'Wages Aswin 2083'], array_column($ledger['rows'], 'entry'));
        $this->assertSame([-3000.0, -2500.0, -1500.0], array_column($ledger['rows'], 'balance'));
        $this->assertSame(-1500.0, $ledger['closing']);

        // From a date: earlier entries roll into the opening balance.
        $later = $this->mason->ledger(null, '2026-09-25');
        $this->assertSame(-3000.0, $later['opening']);
        $this->assertCount(2, $later['rows']);

        // Only one site: its entries and a site-only running balance.
        $this->assertCount(0, $this->mason->ledger($this->siteB->id)['rows']);
    }

    public function test_the_ledger_page_records_payments_for_those_with_the_power(): void
    {
        $this->approvedMonth($this->siteA, '2026-09-22', 6);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin);

        Livewire::test(LabourerLedger::class, ['record' => $this->mason->getRouteKey()])
            ->assertSee('Wages Aswin 2083')
            ->mountAction('recordEntry')
            ->assertActionDataSet(['type' => 'wage', 'amount' => 1000.0])
            ->setActionData(['project_id' => $this->siteA->id, 'amount' => 1200])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame(-200.0, $this->mason->refresh()->balance());

        // Team members without the power cannot open ledgers; with it they can.
        Filament::setCurrentPanel(Filament::getPanel('team'));
        $this->actingAs($this->supervisorUser);
        $this->get(LabourerResource::getUrl('ledger', ['record' => $this->mason], panel: 'team'))->assertForbidden();

        $this->teamRole->givePermissionTo('pay_labour_wages');
        $this->supervisorUser->refresh();
        Livewire::test(LabourerLedger::class, ['record' => $this->mason->getRouteKey()])->assertOk();
    }

    public function test_the_labourer_list_shows_balances_and_filters_by_account(): void
    {
        $this->approvedMonth($this->siteA, '2026-09-22', 6);
        $helper = Labourer::create(['name' => 'Sita', 'work_type' => 'helper', 'daily_wage' => 800]);
        WagePayment::create(['project_id' => $this->siteA->id, 'labourer_id' => $helper->id, 'type' => 'advance', 'amount' => 700, 'paid_on' => '2026-10-01']);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin);

        Livewire::test(ManageLabourers::class)
            ->assertTableColumnStateSet('balance', 1000.0, $this->mason)
            ->assertTableColumnStateSet('advance', 700.0, $helper)
            ->filterTable('account', 'due')
            ->assertCanSeeTableRecords([$this->mason])
            ->assertCanNotSeeTableRecords([$helper])
            ->filterTable('account', 'advance')
            ->assertCanSeeTableRecords([$helper])
            ->assertCanNotSeeTableRecords([$this->mason]);
    }

    public function test_naike_account_and_printouts(): void
    {
        $this->approvedMonth($this->siteA, '2026-09-22', 6);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin);

        Livewire::test(NaikeAccount::class, ['record' => $this->mason->labour_contractor_id])
            ->assertSee('Ram Bahadur')
            ->assertSee('1,000.00');

        $this->get(route('site.labourers.ledger', $this->mason))->assertOk()->assertSee('Labourer Ledger (Khata)', false)->assertSee('Wages Aswin 2083');
        $this->get(route('site.naikes.ledger', $this->mason->labour_contractor_id))->assertOk()->assertSee('Naike Account');

        $this->actingAs($this->supervisorUser)->get(route('site.labourers.ledger', $this->mason))->assertForbidden();
    }
}
