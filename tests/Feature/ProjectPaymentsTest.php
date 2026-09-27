<?php

namespace Tests\Feature;

use App\Filament\Client\Resources\ProjectResource\Pages\ViewProject;
use App\Filament\Resources\PaymentResource\Pages\CreatePayment;
use App\Filament\Resources\ProjectPaymentSubmissionResource;
use App\Filament\Resources\ProjectPaymentSubmissionResource\Pages\ListProjectPaymentSubmissions;
use App\Models\Client;
use App\Models\ClientType;
use App\Models\Payment;
use App\Models\Project;
use App\Models\ProjectPaymentSubmission;
use App\Models\ProjectType;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProjectPaymentsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $clientUser;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $superAdmin = Role::create(['name' => 'super_admin']);

        foreach (['view_any', 'view', 'create', 'update', 'delete'] as $action) {
            $superAdmin->givePermissionTo(Permission::create(['name' => "{$action}_payment"]));
        }

        $this->admin = User::factory()->create(['phone' => '9800000000']);
        $this->admin->assignRole($superAdmin);
        $this->actingAs($this->admin);

        $this->clientUser = User::factory()->create(['phone' => '9811111111']);
        $client = Client::create([
            'user_id' => $this->clientUser->id,
            'contact_person' => 'Shishir Sharma',
            'client_type_id' => ClientType::create(['name' => 'Individual', 'slug' => 'individual'])->id,
            'contact' => '9811111111',
            'address' => 'Belbari',
        ]);

        $this->project = Project::create([
            'client_id' => $client->id,
            'project_type_id' => ProjectType::create(['name' => 'Interior', 'slug' => 'interior', 'is_active' => true])->id,
            'title' => 'Interior – Shishir Sharma',
            'description' => 'Interior work',
            'site_address' => 'Belbari',
            'status' => 'planning',
            'fee' => null,
            'created_by' => $this->admin->id,
        ]);
    }

    public function test_admin_cannot_record_a_payment_before_the_fee_is_set(): void
    {
        Livewire::test(CreatePayment::class)
            ->fillForm(['project_id' => $this->project->id, 'amount' => 25000, 'received_at' => today()])
            ->call('create')
            ->assertHasFormErrors(['amount']);

        $this->assertSame(0, Payment::count());
    }

    public function test_admin_payments_cannot_exceed_the_remaining_balance(): void
    {
        $this->project->update(['fee' => 100000]);
        Payment::create(['project_id' => $this->project->id, 'amount' => 80000, 'received_at' => today(), 'recorded_by' => $this->admin->id]);

        Livewire::test(CreatePayment::class)
            ->fillForm(['project_id' => $this->project->id, 'amount' => 25000, 'received_at' => today()])
            ->call('create')
            ->assertHasFormErrors(['amount']);

        Livewire::test(CreatePayment::class)
            ->fillForm(['project_id' => $this->project->id, 'amount' => 20000, 'received_at' => today(), 'remark' => 'Cash at office'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(100000.0, $this->project->refresh()->amountPaid());
        $this->assertSame(0.0, $this->project->balanceDue());
    }

    public function test_due_now_follows_completed_milestones(): void
    {
        $this->project->update(['fee' => 100000]);
        $survey = $this->project->milestones()->create(['title' => 'Survey', 'sequence' => 1, 'billing_percent' => 10, 'status' => 'pending']);
        $this->project->milestones()->create(['title' => 'Design', 'sequence' => 2, 'billing_percent' => 90, 'status' => 'pending']);

        $this->assertSame(0.0, $this->project->amountDueNow());

        $survey->update(['status' => 'completed']);

        $this->assertSame(10000.0, $this->project->refresh()->amountDueNow());
    }

    public function test_milestone_payments_are_filled_in_and_capped_at_the_milestone_share(): void
    {
        $this->project->update(['fee' => 25000]);
        $survey = $this->project->milestones()->create(['title' => 'Site Survey & Brief', 'sequence' => 1, 'billing_percent' => 10, 'status' => 'completed']);

        $this->assertSame(2500.0, $survey->billingAmount());
        $this->assertSame('Unpaid', $survey->paymentState());

        Livewire::test(CreatePayment::class)
            ->fillForm(['project_id' => $this->project->id])
            ->fillForm(['milestone_id' => $survey->id])
            ->assertFormSet(['amount' => 2500.0])
            ->fillForm(['amount' => 3000, 'received_at' => today()])
            ->call('create')
            ->assertHasFormErrors(['amount']);

        Livewire::test(CreatePayment::class)
            ->fillForm(['project_id' => $this->project->id, 'milestone_id' => $survey->id, 'amount' => 1000, 'received_at' => today()])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('Part paid', $survey->refresh()->paymentState());
        $this->assertSame(1500.0, $survey->amountLeft());

        // Advances without a milestone are still allowed up to the project balance.
        Livewire::test(CreatePayment::class)
            ->fillForm(['project_id' => $this->project->id, 'amount' => 5000, 'received_at' => today(), 'remark' => 'Advance'])
            ->call('create')
            ->assertHasNoFormErrors();
    }

    public function test_overpayments_roll_forward_to_the_next_milestones_in_order(): void
    {
        $this->project->update(['fee' => 100000]);
        $survey = $this->project->milestones()->create(['title' => 'Survey', 'sequence' => 1, 'billing_percent' => 10, 'status' => 'completed']);
        $design = $this->project->milestones()->create(['title' => 'Design', 'sequence' => 2, 'billing_percent' => 40, 'status' => 'pending']);
        $execution = $this->project->milestones()->create(['title' => 'Execution', 'sequence' => 3, 'billing_percent' => 50, 'status' => 'pending']);

        Payment::create(['project_id' => $this->project->id, 'milestone_id' => $survey->id, 'amount' => 5000, 'received_at' => today(), 'recorded_by' => $this->admin->id]);
        Payment::create(['project_id' => $this->project->id, 'amount' => 25000, 'received_at' => today(), 'remark' => 'Advance', 'recorded_by' => $this->admin->id]);

        $this->assertSame([$survey->id => 10000.0, $design->id => 20000.0, $execution->id => 0.0], $this->project->refresh()->milestoneAllocations());

        $this->assertSame('Paid', $survey->refresh()->paymentState());
        $this->assertSame(0.0, $survey->amountLeft());
        $this->assertSame('Part paid in advance', $design->refresh()->paymentState());
        $this->assertSame(20000.0, $design->amountLeft());
        $this->assertSame('Unpaid', $execution->refresh()->paymentState());
        $this->assertSame(0.0, $this->project->amountDueNow());

        // Once Design is completed, only its uncovered part is due.
        $design->update(['status' => 'completed']);

        $this->assertSame('Part paid', $design->refresh()->paymentState());
        $this->assertSame(20000.0, $this->project->refresh()->amountDueNow());
    }

    public function test_a_client_submits_a_payment_and_an_admin_approves_it(): void
    {
        $this->project->update(['fee' => 100000]);

        $this->actAsClient();

        Livewire::test(ViewProject::class, ['record' => $this->project->getRouteKey()])
            ->callAction('makePayment', ['amount' => 150000, 'transaction_reference' => 'ESEWA-1'])
            ->assertHasActionErrors(['amount']);

        Livewire::test(ViewProject::class, ['record' => $this->project->getRouteKey()])
            ->callAction('makePayment', ['amount' => 25000, 'transaction_reference' => 'ESEWA-2'])
            ->assertHasNoActionErrors();

        $submission = ProjectPaymentSubmission::firstOrFail();
        $this->assertSame('pending', $submission->status);

        $notification = $this->admin->unreadNotifications()->sole();
        $this->assertSame('Payment submitted: NPR 25,000.00', $notification->data['title']);
        $this->assertStringContainsString('ESEWA-2', $notification->data['body']);
        $this->assertSame($this->clientUser->id, $submission->submitted_by);
        $this->assertSame(0, Payment::count());

        // Pending submissions count against the balance, so the client cannot over-submit.
        Livewire::test(ViewProject::class, ['record' => $this->project->getRouteKey()])
            ->callAction('makePayment', ['amount' => 80000, 'transaction_reference' => 'ESEWA-3'])
            ->assertHasActionErrors(['amount']);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin);

        Livewire::test(ListProjectPaymentSubmissions::class)
            ->assertCanSeeTableRecords([$submission])
            ->callTableAction('approve', $submission, ['review_note' => 'Received in bank']);

        $submission->refresh();
        $this->assertSame('approved', $submission->status);
        $this->assertNotNull($submission->payment_id);
        $this->assertSame(25000.0, $this->project->refresh()->amountPaid());
    }

    public function test_admin_notifications_arrive_without_a_queue_worker(): void
    {
        config(['queue.default' => 'database']);
        $this->project->update(['fee' => 100000]);
        $this->actAsClient();

        $this->project->paymentSubmissions()->create(['amount' => 5000, 'transaction_reference' => 'ESEWA-9', 'submitted_by' => $this->clientUser->id]);

        $this->assertSame(1, $this->admin->unreadNotifications()->count());
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_the_make_payment_button_is_hidden_until_a_fee_is_agreed(): void
    {
        $this->actAsClient();

        Livewire::test(ViewProject::class, ['record' => $this->project->getRouteKey()])
            ->assertActionHidden('makePayment');
    }

    public function test_rejecting_keeps_the_reason_and_records_nothing(): void
    {
        $this->project->update(['fee' => 100000]);
        $submission = $this->project->paymentSubmissions()->create(['amount' => 5000, 'transaction_reference' => 'WRONG', 'submitted_by' => $this->clientUser->id]);

        Livewire::test(ListProjectPaymentSubmissions::class)
            ->callTableAction('reject', $submission, ['review_note' => 'Not found in bank statement']);

        $submission->refresh();
        $this->assertSame('rejected', $submission->status);
        $this->assertSame('Not found in bank statement', $submission->review_note);
        $this->assertSame(0, Payment::count());
    }

    public function test_approval_is_refused_when_the_balance_was_already_paid_another_way(): void
    {
        $this->project->update(['fee' => 100000]);
        $submission = $this->project->paymentSubmissions()->create(['amount' => 30000, 'transaction_reference' => 'LATE', 'submitted_by' => $this->clientUser->id]);
        Payment::create(['project_id' => $this->project->id, 'amount' => 90000, 'received_at' => today(), 'recorded_by' => $this->admin->id]);

        Livewire::test(ListProjectPaymentSubmissions::class)
            ->callTableAction('approve', $submission);

        $this->assertSame('pending', $submission->refresh()->status);
        $this->assertSame(1, Payment::count());
    }

    public function test_submissions_are_not_available_in_the_team_panel(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('team'));

        $this->assertFalse(ProjectPaymentSubmissionResource::canAccess());
    }

    protected function actAsClient(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('client'));
        $this->actingAs($this->clientUser);
    }
}
