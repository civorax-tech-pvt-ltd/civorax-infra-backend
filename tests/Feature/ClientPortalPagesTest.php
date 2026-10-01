<?php

namespace Tests\Feature;

use App\Filament\Client\Resources\DocumentResource;
use App\Filament\Client\Resources\DocumentResource\Pages\ListDocuments;
use App\Filament\Client\Resources\PaymentResource;
use App\Filament\Client\Resources\PaymentResource\Pages\ListPayments;
use App\Filament\Client\Resources\ProjectResource\Pages\ViewProject;
use App\Filament\Client\Resources\ProjectResource\RelationManagers\WorkProgressRelationManager;
use App\Filament\Client\Resources\QuotationResource\Pages\ListQuotations;
use App\Filament\Client\Resources\SiteDiaryResource;
use App\Filament\Client\Resources\SiteDiaryResource\Pages\ListSiteDiary;
use App\Filament\Client\Resources\WorkProgressResource;
use App\Filament\Client\Resources\WorkProgressResource\Pages\ListWorkProgress;
use App\Filament\Resources\ProjectResource\Pages\EditProject;
use App\Models\Client;
use App\Models\ClientType;
use App\Models\Payment;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\ProjectType;
use App\Models\SiteReport;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ClientPortalPagesTest extends TestCase
{
    use RefreshDatabase;

    protected User $clientUser;

    protected Project $project;

    protected Project $strangersProject;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::factory()->create(['phone' => '9800000000']);
        $admin->assignRole(Role::create(['name' => 'super_admin']));
        $type = ProjectType::create(['name' => 'Residential', 'slug' => 'residential', 'is_active' => true]);
        $clientType = ClientType::create(['name' => 'Individual', 'slug' => 'individual']);

        $this->clientUser = User::factory()->create(['phone' => '9811111111']);
        $client = Client::create(['user_id' => $this->clientUser->id, 'contact_person' => 'Shishir Sharma', 'client_type_id' => $clientType->id, 'contact' => '9811111111', 'address' => 'Belbari']);
        $stranger = Client::create(['user_id' => User::factory()->create(['phone' => '9833333333'])->id, 'contact_person' => 'Someone Else', 'client_type_id' => $clientType->id, 'contact' => '9833333333', 'address' => 'Dharan']);

        $this->project = Project::create(['client_id' => $client->id, 'project_type_id' => $type->id, 'title' => 'House – Belbari', 'description' => 'x', 'site_address' => 'Belbari', 'status' => 'execution', 'fee' => 1800000, 'created_by' => $admin->id]);
        $this->strangersProject = Project::create(['client_id' => $stranger->id, 'project_type_id' => $type->id, 'title' => 'Other – Dharan', 'description' => 'x', 'site_address' => 'Dharan', 'status' => 'execution', 'fee' => 900000, 'created_by' => $admin->id]);

        foreach ([$this->project, $this->strangersProject] as $project) {
            Payment::create(['project_id' => $project->id, 'amount' => 50000, 'received_at' => '2026-09-01', 'recorded_by' => $admin->id]);
            ProjectDocument::create(['project_id' => $project->id, 'title' => "Plan {$project->id}", 'type' => 'drawing', 'file_path' => 'x.pdf', 'uploaded_by' => $admin->id]);
            $project->quotations()->create(['status' => 'sent']);
            SiteReport::create(['project_id' => $project->id, 'date' => '2026-09-20', 'work_done' => "Slab work {$project->id}", 'status' => 'approved']);
        }
        SiteReport::create(['project_id' => $this->project->id, 'date' => '2026-09-21', 'work_done' => 'Unapproved draft', 'status' => 'submitted']);

        Filament::setCurrentPanel(Filament::getPanel('client'));
        $this->actingAs($this->clientUser);
    }

    public function test_sidebar_pages_list_only_the_clients_own_records(): void
    {
        Livewire::test(ListPayments::class)
            ->assertCanSeeTableRecords($this->project->payments)
            ->assertCountTableRecords(1);

        Livewire::test(ListDocuments::class)->assertSee('Plan '.$this->project->id)->assertDontSee('Plan '.$this->strangersProject->id);
        Livewire::test(ListQuotations::class)->assertCountTableRecords(1)->assertSee('Review & respond');

        Livewire::test(ListSiteDiary::class)
            ->assertSee('Slab work '.$this->project->id)
            ->assertDontSee('Slab work '.$this->strangersProject->id)
            ->assertDontSee('Unapproved draft');
    }

    public function test_boq_progress_is_hidden_until_the_admin_shares_it_and_never_shows_rates(): void
    {
        $item = $this->project->boqItems()->create(['description' => 'RCC slab', 'unit' => 'm³', 'quantity' => 45, 'rate' => 15000]);
        $item->measurements()->create(['measured_date' => '2026-09-25', 'executed_quantity' => 30, 'status' => 'approved']);

        $this->assertFalse(WorkProgressResource::shouldRegisterNavigation());
        $this->assertFalse(WorkProgressRelationManager::canViewForRecord($this->project, ViewProject::class));

        $this->project->update(['share_boq_with_client' => true]);

        $this->assertTrue(WorkProgressResource::shouldRegisterNavigation());
        $this->assertTrue(WorkProgressRelationManager::canViewForRecord($this->project->refresh(), ViewProject::class));

        Livewire::test(ListWorkProgress::class)
            ->assertSee('RCC slab')
            ->assertSee('30 m³')
            ->assertSee('67%')
            ->assertDontSee('15,000')
            ->assertDontSee('675,000');
    }

    public function test_the_admin_shares_an_agreement_and_the_client_can_download_it(): void
    {
        Storage::fake('public');

        Livewire::test(ViewProject::class, ['record' => $this->project->getRouteKey()])->assertActionHidden('agreement');

        // Admin uploads it from the project edit page.
        $admin = User::role('super_admin')->first();
        foreach (['view_any_project', 'view_project', 'update_project'] as $permission) {
            Role::findByName('super_admin')->givePermissionTo(Permission::findOrCreate($permission));
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);
        Livewire::test(EditProject::class, ['record' => $this->project->getRouteKey()])
            ->callAction('agreement', ['title' => 'Construction agreement', 'file_path' => [UploadedFile::fake()->create('agreement.pdf', 200, 'application/pdf')]])
            ->assertHasNoActionErrors();

        $agreement = $this->project->agreement();
        $this->assertSame('Construction agreement', $agreement->title);
        $this->assertSame(1, $agreement->version);
        $this->assertStringStartsWith('agreements/', $agreement->file_path);
        $this->assertTrue($this->clientUser->notifications()->get()->contains(fn ($n): bool => $n->data['title'] === 'New document: Construction agreement'));

        // The client sees a download button on the project and the agreement first in Documents.
        Filament::setCurrentPanel(Filament::getPanel('client'));
        $this->actingAs($this->clientUser);
        Livewire::test(ViewProject::class, ['record' => $this->project->getRouteKey()])
            ->assertActionVisible('agreement')
            ->assertActionHasUrl('agreement', $agreement->url());

        Livewire::test(ListDocuments::class)
            ->assertSee('Agreement / contract')
            ->assertCanSeeTableRecords([$agreement, ...$this->project->documents()->where('type', '!=', 'agreement')->get()], inOrder: true);
    }

    public function test_client_pages_cannot_create_or_edit_anything(): void
    {
        foreach ([PaymentResource::class, DocumentResource::class, SiteDiaryResource::class] as $resource) {
            $this->assertTrue($resource::canViewAny());
            $this->assertFalse($resource::canCreate());
        }
    }
}
