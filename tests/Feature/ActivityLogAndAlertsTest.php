<?php

namespace Tests\Feature;

use App\Filament\Resources\ActivityLogResource;
use App\Filament\Resources\ActivityLogResource\Pages\ListActivityLogs;
use App\Models\Client;
use App\Models\ClientType;
use App\Models\EquipmentEntry;
use App\Models\Project;
use App\Models\ProjectType;
use App\Models\User;
use App\Models\Variation;
use App\Models\Vendor;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ActivityLogAndAlertsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $engineer;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['phone' => '9800000000', 'name' => 'Super Admin']);
        $this->admin->assignRole(Role::create(['name' => 'super_admin']));
        $this->engineer = User::factory()->create(['phone' => '9822222222', 'name' => 'Hari Engineer']);
        $this->engineer->assignRole(Role::create(['name' => 'engineer']));

        $this->project = Project::create([
            'client_id' => Client::create([
                'user_id' => User::factory()->create(['phone' => '9811111111'])->id, 'contact_person' => 'Shishir Sharma',
                'client_type_id' => ClientType::create(['name' => 'Individual', 'slug' => 'individual'])->id,
                'contact' => '9811111111', 'address' => 'Belbari',
            ])->id,
            'project_type_id' => ProjectType::create(['name' => 'Residential', 'slug' => 'residential', 'is_active' => true])->id,
            'title' => 'House – Belbari', 'description' => 'x', 'site_address' => 'Belbari', 'status' => 'execution', 'created_by' => $this->admin->id,
        ]);
    }

    public function test_changes_are_logged_with_old_and_new_values_and_shown_to_super_admins(): void
    {
        $this->actingAs($this->engineer);
        $vendor = Vendor::create(['name' => 'Shree Hardware', 'vendor_type' => 'supplier', 'contact' => '9844444444', 'created_by' => $this->engineer->id]);
        $vendor->update(['contact' => '9855555555']);

        $log = Activity::query()->where('subject_type', Vendor::class)->where('event', 'updated')->sole();
        $this->assertSame($this->engineer->id, $log->causer_id);
        $this->assertSame(['contact' => '9844444444'], $log->properties['old']);
        $this->assertSame(['contact' => '9855555555'], $log->properties['attributes']);

        // Saving without changes adds nothing.
        $vendor->update(['contact' => '9855555555']);
        $this->assertSame(1, Activity::query()->where('subject_type', Vendor::class)->where('event', 'updated')->count());

        $this->assertSame([['field' => 'Contact', 'old' => '9844444444', 'new' => '9855555555']], ActivityLogResource::changes($log));

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin);
        $this->get(ActivityLogResource::getUrl())->assertOk()->assertSee('Hari Engineer')->assertSee('Shree Hardware');
        Livewire::test(ListActivityLogs::class)
            ->filterTable('subject_type', Vendor::class)
            ->assertCanSeeTableRecords([$log])
            ->mountTableAction('details', $log)
            ->assertSee('9844444444')
            ->assertSee('9855555555');

        // Passwords are never written to the log.
        $this->admin->update(['password' => 'secret-password-123']);
        $this->assertFalse(Activity::query()->where('subject_type', User::class)->get()->contains(fn (Activity $a): bool => isset($a->properties['attributes']['password'])));

        $this->actingAs($this->engineer);
        $this->assertFalse(ActivityLogResource::canAccess());
    }

    public function test_entrants_hear_about_rejected_extra_work_and_reviewed_equipment(): void
    {
        $variation = Variation::create(['project_id' => $this->project->id, 'title' => 'Extra parapet wall', 'amount' => 45000, 'entered_by' => $this->engineer->id]);
        $variation->reject($this->admin, 'Already in the contract.');

        $entry = EquipmentEntry::create(['project_id' => $this->project->id, 'kind' => 'equipment', 'description' => 'JCB excavation', 'entry_date' => '2026-10-02', 'unit' => 'hour', 'quantity' => 4, 'rate' => 3500, 'entered_by' => $this->engineer->id]);
        $entry->approve($this->admin);

        $titles = $this->engineer->notifications()->get()->map(fn ($n): string => $n->data['title'])->all();
        $this->assertContains('Extra work rejected: Extra parapet wall', $titles);
        $this->assertTrue(collect($titles)->contains(fn (string $t): bool => str_starts_with($t, 'Approved: ')));
    }
}
