<?php

namespace Tests\Feature;

use App\Filament\Auth\EditMyProfile;
use App\Models\Client;
use App\Models\ClientType;
use App\Models\Student;
use App\Models\TeamMember;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProfilePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_portal_has_a_profile_page_in_the_user_menu(): void
    {
        $client = User::factory()->create(['phone' => '9811111111']);
        Client::create(['user_id' => $client->id, 'contact_person' => 'Shishir', 'client_type_id' => ClientType::create(['name' => 'Individual', 'slug' => 'individual'])->id, 'contact' => '9811111111', 'address' => 'Belbari']);

        $student = User::factory()->create(['phone' => '9844444444']);
        Student::create(['user_id' => $student->id, 'fullname' => 'Divash', 'dob' => '2004-01-01', 'contact' => '9844444444', 'address' => 'Itahari']);

        $admin = User::factory()->create(['phone' => '9800000000']);
        $admin->assignRole(Role::create(['name' => 'super_admin']));

        $member = User::factory()->create(['phone' => '9822222222']);
        $member->assignRole(Role::create(['name' => 'site_engineer']));
        TeamMember::create([
            'user_id' => $member->id, 'fullname' => 'Ram', 'designation' => 'Civil Engineer', 'contact1' => '9822222222', 'marital_status' => 'Single',
            'national_id_path' => 'ids/id.jpg', 'bank_name' => 'Nabil', 'bank_account_name' => 'Ram', 'bank_account_number' => '1', 'created_by' => $admin->id,
        ]);

        foreach (['client' => $client, 'student' => $student, 'team' => $member, 'admin' => $admin] as $panel => $user) {
            $url = Filament::getPanel($panel)->getProfileUrl();
            $this->actingAs($user)->get($url)->assertOk()->assertSee('My profile')->assertSee('Change password')->assertSee($user->phone);
            $this->actingAs($user)->get(Filament::getPanel($panel)->getUrl())->assertSee($url, false);
        }

        $this->actingAs($member)->get(Filament::getPanel('team')->getProfileUrl())->assertSee('Team · Site Engineer');
    }

    public function test_password_change_needs_the_current_password_and_is_logged_without_the_password(): void
    {
        $user = User::factory()->create(['phone' => '9811111111', 'password' => 'old-secret-1']);
        Client::create(['user_id' => $user->id, 'contact_person' => 'Shishir', 'client_type_id' => ClientType::create(['name' => 'Individual', 'slug' => 'individual'])->id, 'contact' => '9811111111', 'address' => 'Belbari']);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('client'));

        Livewire::test(EditMyProfile::class)
            ->fillForm(['password' => 'new-secret-2', 'passwordConfirmation' => 'new-secret-2', 'currentPassword' => 'wrong'])
            ->call('save')
            ->assertHasFormErrors(['currentPassword']);
        $this->assertTrue(Hash::check('old-secret-1', $user->fresh()->password));

        Livewire::test(EditMyProfile::class)
            ->fillForm(['name' => 'Shishir Rai', 'email' => 'shishir@example.com', 'phone' => '9899999999', 'password' => 'new-secret-2', 'passwordConfirmation' => 'new-secret-2', 'currentPassword' => 'old-secret-1'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Profile saved and password changed');

        $user->refresh();
        $this->assertTrue(Hash::check('new-secret-2', $user->password));
        $this->assertSame('Shishir Rai', $user->name);
        $this->assertSame('9811111111', $user->phone, 'The login phone is not changed from the profile page.');

        $log = Activity::query()->where('description', 'Password changed')->sole();
        $this->assertSame($user->id, $log->causer_id);
        $this->assertStringNotContainsString('new-secret-2', json_encode($log->properties));
        $this->assertStringNotContainsString($user->password, (string) Activity::query()->get()->toJson());
    }
}
