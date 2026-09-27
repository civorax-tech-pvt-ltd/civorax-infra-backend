<?php

namespace Tests\Feature;

use App\Filament\Resources\TeamMemberResource\Pages\CreateTeamMember;
use App\Filament\Resources\TeamMemberResource\Pages\EditTeamMember;
use App\Models\TeamMember;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TeamMemberResourceTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Storage::fake('local');

        $this->admin = User::factory()->create(['phone' => '9800000000']);
        $superAdmin = Role::create(['name' => 'super_admin']);

        foreach (['view_any', 'view', 'create', 'update'] as $action) {
            $superAdmin->givePermissionTo(Permission::create(['name' => "{$action}_team::member"]));
        }

        $this->admin->assignRole($superAdmin);

        $this->actingAs($this->admin);
    }

    public function test_it_creates_the_login_account_and_team_member_from_one_form(): void
    {
        $role = Role::create(['name' => 'site_engineer']);

        Livewire::test(CreateTeamMember::class)
            ->fillForm([
                'user.phone' => '9811111111',
                'user.email' => 'bipul@example.com',
                'user.password' => 'secret-password',
                'fullname' => 'Bipul Tharu',
                'contact1' => '9811111111',
                'marital_status' => 'Single',
                'national_id_path' => UploadedFile::fake()->image('citizenship.jpg'),
                'bank_name' => 'NIC Asia',
                'bank_account_name' => 'Bipul Tharu',
                'bank_account_number' => '0123456789',
                'roles' => [$role->id],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::where('phone', '9811111111')->firstOrFail();

        $this->assertSame('Bipul Tharu', $user->name);
        $this->assertTrue(Hash::check('secret-password', $user->password));
        $this->assertTrue($user->hasRole('site_engineer'));

        $teamMember = $user->teamMember;

        $this->assertNotNull($teamMember);
        $this->assertSame($this->admin->id, $teamMember->created_by);
        Storage::disk('local')->assertExists($teamMember->national_id_path);
    }

    public function test_it_rejects_a_phone_number_that_is_already_used(): void
    {
        Livewire::test(CreateTeamMember::class)
            ->fillForm([
                'user.phone' => $this->admin->phone,
                'user.password' => 'secret-password',
            ])
            ->call('create')
            ->assertHasFormErrors(['user.phone' => 'unique']);
    }

    public function test_editing_keeps_the_password_when_left_blank_and_syncs_the_account_name(): void
    {
        $user = User::factory()->create(['phone' => '9822222222', 'password' => 'original-password']);
        $teamMember = TeamMember::create([
            'user_id' => $user->id,
            'fullname' => 'Hari Bdr',
            'contact1' => '9822222222',
            'marital_status' => 'Married',
            'national_id_path' => 'team-members/national-ids/hari.jpg',
            'bank_name' => 'Nabil',
            'bank_account_name' => 'Hari Bdr',
            'bank_account_number' => '111',
            'created_by' => $this->admin->id,
        ]);

        Storage::disk('local')->put('team-members/national-ids/hari.jpg', 'id');

        Livewire::test(EditTeamMember::class, ['record' => $teamMember->getRouteKey()])
            ->assertFormSet(['user.phone' => '9822222222'])
            ->fillForm([
                'user.phone' => '9822222222',
                'fullname' => 'Hari Bahadur',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $user->refresh();

        $this->assertSame('Hari Bahadur', $user->name);
        $this->assertTrue(Hash::check('original-password', $user->password));
    }
}
