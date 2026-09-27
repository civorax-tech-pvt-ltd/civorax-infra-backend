<?php

namespace Tests\Feature;

use App\Filament\Client\Resources\ProjectResource;
use App\Filament\Client\Resources\ProjectResource\Pages\ListProjects;
use App\Filament\Client\Resources\ProjectResource\Pages\ViewProject;
use App\Filament\Client\Resources\ProjectResource\RelationManagers\QuotationsRelationManager;
use App\Models\Client;
use App\Models\ClientType;
use App\Models\Project;
use App\Models\ProjectType;
use App\Models\Quotation;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ClientQuotationResponseTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $clientUser;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = $this->admin = User::factory()->create(['phone' => '9800000000']);
        $admin->assignRole(Role::create(['name' => 'super_admin']));
        $clientType = ClientType::create(['name' => 'Individual', 'slug' => 'individual']);
        $projectType = ProjectType::create(['name' => 'Interior', 'slug' => 'interior', 'is_active' => true]);

        $this->clientUser = User::factory()->create(['phone' => '9811111111']);
        $this->project = $this->makeProjectFor($this->clientUser, $clientType, $projectType, $admin);

        Filament::setCurrentPanel(Filament::getPanel('client'));
        $this->actingAs($this->clientUser);
    }

    public function test_the_client_accepts_a_sent_quotation_and_the_fee_is_set(): void
    {
        $quotation = $this->makeQuotation('sent', 30000);

        $this->quotationsTab()
            ->assertTableActionVisible('accept', $quotation)
            ->callTableAction('accept', $quotation);

        $quotation->refresh();
        $this->assertSame('accepted', $quotation->status);
        $this->assertSame($this->clientUser->id, $quotation->accepted_by);
        $this->assertNotNull($quotation->accepted_at);
        $this->assertSame('30000.00', $this->project->refresh()->fee);

        $this->quotationsTab()->assertTableActionHidden('accept', $quotation);

        $notification = $this->admin->unreadNotifications()->sole();
        $this->assertSame('v1 accepted by client', str($notification->data['title'])->after('Quotation ')->toString());
        $this->assertStringContainsString('activeRelationManager=2', $notification->data['actions'][0]['url']);
    }

    public function test_admins_are_not_notified_when_they_accept_a_quotation_themselves(): void
    {
        $this->makeQuotation('sent', 30000)->accept($this->admin);

        $this->assertSame(0, $this->admin->notifications()->count());
    }

    public function test_the_client_requests_changes_with_a_note(): void
    {
        $quotation = $this->makeQuotation('sent', 30000);

        $this->quotationsTab()
            ->callTableAction('requestChanges', $quotation, ['note' => 'Please drop the 3D renders.'])
            ->assertHasNoTableActionErrors();

        $quotation->refresh();
        $this->assertSame('changes_requested', $quotation->status);
        $this->assertSame('Please drop the 3D renders.', $quotation->client_note);
        $this->assertNotNull($quotation->client_responded_at);
        $this->assertNull($this->project->refresh()->fee);

        $notification = $this->admin->unreadNotifications()->sole();
        $this->assertSame('Changes requested on Quotation v1', $notification->data['title']);
        $this->assertStringContainsString('Please drop the 3D renders.', $notification->data['body']);

        $this->quotationsTab()
            ->assertCanSeeTableRecords([$quotation])
            ->assertTableActionHidden('accept', $quotation)
            ->assertTableActionHidden('requestChanges', $quotation);
    }

    public function test_expired_and_draft_quotations_cannot_be_answered(): void
    {
        $expired = $this->makeQuotation('sent', 30000, validUntil: now()->subDay());
        $draft = $this->makeQuotation('draft', 20000);

        $this->quotationsTab()
            ->assertCanSeeTableRecords([$expired])
            ->assertCanNotSeeTableRecords([$draft])
            ->assertTableActionHidden('accept', $expired)
            ->assertTableActionHidden('requestChanges', $expired);
    }

    public function test_a_client_cannot_open_another_clients_project(): void
    {
        $otherUser = User::factory()->create(['phone' => '9822222222']);
        $otherProject = $this->makeProjectFor($otherUser, ClientType::first(), ProjectType::first(), User::first());

        Livewire::test(ListProjects::class)
            ->assertCanSeeTableRecords([$this->project])
            ->assertCanNotSeeTableRecords([$otherProject]);

        $this->get(ProjectResource::getUrl('view', ['record' => $otherProject]))
            ->assertNotFound();
    }

    protected function quotationsTab(): Testable
    {
        return Livewire::test(QuotationsRelationManager::class, ['ownerRecord' => $this->project, 'pageClass' => ViewProject::class]);
    }

    protected function makeQuotation(string $status, float $rate, ?\DateTimeInterface $validUntil = null): Quotation
    {
        $quotation = $this->project->quotations()->create(['status' => $status, 'valid_until' => $validUntil ?? now()->addMonth()]);
        $quotation->items()->create(['description' => 'Interior design', 'quantity' => 1, 'unit' => 'lump sum', 'rate' => $rate]);

        return $quotation->refresh();
    }

    protected function makeProjectFor(User $user, ClientType $clientType, ProjectType $projectType, User $admin): Project
    {
        $client = Client::create([
            'user_id' => $user->id,
            'contact_person' => 'Client '.$user->phone,
            'client_type_id' => $clientType->id,
            'contact' => $user->phone,
            'address' => 'Belbari',
        ]);

        return Project::create([
            'client_id' => $client->id,
            'project_type_id' => $projectType->id,
            'title' => 'Interior – '.$client->contact_person,
            'description' => 'Interior work',
            'site_address' => 'Belbari',
            'status' => 'inquiry',
            'created_by' => $admin->id,
        ]);
    }
}
