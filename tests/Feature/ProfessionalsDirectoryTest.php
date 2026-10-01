<?php

namespace Tests\Feature;

use App\Filament\Resources\ProfessionalResource;
use App\Filament\Resources\ProfessionalResource\Pages\CreateProfessional;
use App\Filament\Resources\ProfessionalResource\Pages\ListProfessionals;
use App\Models\Client;
use App\Models\ClientType;
use App\Models\Professional;
use App\Models\Project;
use App\Models\ProjectType;
use App\Models\TeamMember;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProfessionalsDirectoryTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $memberUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['phone' => '9800000000']);
        $this->admin->assignRole(Role::create(['name' => 'super_admin']));

        $this->memberUser = User::factory()->create(['phone' => '9822222222', 'name' => 'Hari Engineer']);
        $this->memberUser->assignRole(Role::create(['name' => 'site_engineer']));
        TeamMember::create([
            'user_id' => $this->memberUser->id, 'fullname' => 'Hari Engineer', 'contact1' => '9822222222', 'marital_status' => 'Single',
            'national_id_path' => 'ids/id.jpg', 'bank_name' => 'Nabil', 'bank_account_name' => 'Hari', 'bank_account_number' => '1',
            'created_by' => $this->admin->id,
        ]);
    }

    protected function professional(array $attributes): Professional
    {
        return Professional::create(['professional_type' => 'mason', 'address' => 'Itahari', 'added_by' => $this->admin->id, ...$attributes]);
    }

    public function test_team_members_add_professionals_with_photo_location_and_their_name_as_adder(): void
    {
        Storage::fake('public');
        Filament::setCurrentPanel(Filament::getPanel('team'));
        $this->actingAs($this->memberUser);

        Livewire::test(CreateProfessional::class)
            ->fillForm([
                'fullname' => 'Ram Bahadur Shrestha',
                'professional_type' => 'electrician',
                'contact' => '9811000001',
                'address' => 'Belbari-3, Morang',
                'latitude' => 26.6621,
                'longitude' => 87.4478,
                'years_of_experience' => 12,
                'photo_path' => [UploadedFile::fake()->image('ram.jpg')],
                'added_by' => $this->admin->id, // ignored: the adder is always the signed-in user
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $professional = Professional::sole();
        $this->assertSame($this->memberUser->id, $professional->added_by);
        $this->assertSame('Electrician', $professional->typeLabel());
        $this->assertSame('https://www.google.com/maps?q=26.6621,87.4478', $professional->mapUrl());
        Storage::disk('public')->assertExists($professional->photo_path);

        // The same number cannot be added twice.
        Livewire::test(CreateProfessional::class)
            ->fillForm(['fullname' => 'Duplicate', 'professional_type' => 'mason', 'contact' => '9811000001', 'address' => 'x'])
            ->call('create')
            ->assertHasFormErrors(['contact' => 'unique']);
    }

    public function test_team_members_edit_only_their_own_entries_and_only_admins_delete(): void
    {
        $theirs = $this->professional(['fullname' => 'A', 'contact' => '1', 'added_by' => $this->memberUser->id]);
        $others = $this->professional(['fullname' => 'B', 'contact' => '2']);

        Filament::setCurrentPanel(Filament::getPanel('team'));
        $this->actingAs($this->memberUser);
        $this->assertTrue(ProfessionalResource::canEdit($theirs));
        $this->assertFalse(ProfessionalResource::canEdit($others));
        $this->assertFalse(ProfessionalResource::canDelete($theirs));

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin);
        $this->assertTrue(ProfessionalResource::canEdit($others));
        $this->assertTrue(ProfessionalResource::canDelete($others));
    }

    public function test_the_near_filter_sorts_by_distance_from_a_site(): void
    {
        $client = Client::create(['user_id' => User::factory()->create(['phone' => '9811111111'])->id, 'contact_person' => 'Client', 'client_type_id' => ClientType::create(['name' => 'Individual', 'slug' => 'individual'])->id, 'contact' => '9811111111', 'address' => 'Belbari']);
        $site = Project::create(['client_id' => $client->id, 'project_type_id' => ProjectType::create(['name' => 'Residential', 'slug' => 'residential', 'is_active' => true])->id, 'title' => 'House – Belbari', 'description' => 'x', 'site_address' => 'Belbari', 'status' => 'execution', 'latitude' => 26.6600, 'longitude' => 87.4400, 'created_by' => $this->admin->id]);

        $far = $this->professional(['fullname' => 'Far (Dharan)', 'contact' => '3', 'latitude' => 26.8120, 'longitude' => 87.2830]);
        $near = $this->professional(['fullname' => 'Near (Belbari)', 'contact' => '4', 'latitude' => 26.6650, 'longitude' => 87.4450]);
        $unknown = $this->professional(['fullname' => 'No location', 'contact' => '5']);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin);

        Livewire::test(ListProfessionals::class)
            ->filterTable('near', "project:{$site->id}")
            ->assertCanSeeTableRecords([$near, $far, $unknown], inOrder: true)
            ->assertTableColumnStateSet('distance', '0.7 km', $near)
            ->assertTableColumnStateSet('distance', $far->distanceFrom(26.66, 87.44).' km', $far);

        Livewire::test(ListProfessionals::class)
            ->filterTable('professional_type', ['mason'])
            ->assertCountTableRecords(3);
    }
}
