<?php

namespace Tests\Feature;

use App\Filament\Resources\ClientResource\Pages\CreateClient;
use App\Filament\Resources\ClientResource\Pages\EditClient;
use App\Filament\Resources\StudentResource\Pages\CreateStudent;
use App\Filament\Resources\StudentResource\Pages\EditStudent;
use App\Models\Client;
use App\Models\ClientType;
use App\Models\Student;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ClientAndStudentLoginAccountTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $superAdmin = Role::create(['name' => 'super_admin']);

        foreach (['client', 'student'] as $resource) {
            foreach (['view_any', 'view', 'create', 'update'] as $action) {
                $superAdmin->givePermissionTo(Permission::create(['name' => "{$action}_{$resource}"]));
            }
        }

        $this->admin = User::factory()->create(['phone' => '9800000000']);
        $this->admin->assignRole($superAdmin);

        $this->actingAs($this->admin);
    }

    public function test_it_creates_a_client_with_its_login_account(): void
    {
        $clientType = ClientType::create(['name' => 'Individual', 'slug' => 'individual']);

        Livewire::test(CreateClient::class)
            ->fillForm([
                'user.phone' => '9811111111',
                'user.password' => 'secret-password',
                'contact_person' => 'Tester Client',
                'client_type_id' => $clientType->id,
                'contact' => '9811111111',
                'address' => 'Kathmandu',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::where('phone', '9811111111')->firstOrFail();

        $this->assertSame('Tester Client', $user->name);
        $this->assertTrue(Hash::check('secret-password', $user->password));
        $this->assertSame('Kathmandu', $user->client->address);
        $this->assertSame($this->admin->id, $user->client->created_by);
    }

    public function test_it_creates_a_student_with_its_login_account(): void
    {
        Livewire::test(CreateStudent::class)
            ->fillForm([
                'user.phone' => '9822222222',
                'user.email' => 'shisir@example.com',
                'user.password' => 'secret-password',
                'fullname' => 'Student Shisir',
                'dob' => '2004-05-01',
                'contact' => '9822222222',
                'address' => 'Pokhara',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::where('phone', '9822222222')->firstOrFail();

        $this->assertSame('Student Shisir', $user->name);
        $this->assertSame('shisir@example.com', $user->email);
        $this->assertNotNull($user->student);
    }

    public function test_it_rejects_a_phone_number_that_is_already_used(): void
    {
        Livewire::test(CreateStudent::class)
            ->fillForm([
                'user.phone' => $this->admin->phone,
                'user.password' => 'secret-password',
            ])
            ->call('create')
            ->assertHasFormErrors(['user.phone' => 'unique']);
    }

    public function test_editing_a_client_updates_the_login_account_and_keeps_a_blank_password(): void
    {
        $user = User::factory()->create(['phone' => '9833333333', 'password' => 'original-password']);
        $client = Client::create([
            'user_id' => $user->id,
            'contact_person' => 'Old Name',
            'client_type_id' => ClientType::create(['name' => 'Company', 'slug' => 'company'])->id,
            'contact' => '9833333333',
            'address' => 'Lalitpur',
        ]);

        Livewire::test(EditClient::class, ['record' => $client->getRouteKey()])
            ->assertFormSet(['user.phone' => '9833333333'])
            ->fillForm([
                'user.phone' => '9844444444',
                'contact_person' => 'New Name',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $user->refresh();

        $this->assertSame('9844444444', $user->phone);
        $this->assertSame('New Name', $user->name);
        $this->assertTrue(Hash::check('original-password', $user->password));
    }

    public function test_editing_a_student_keeps_its_own_phone_number_valid(): void
    {
        $user = User::factory()->create(['phone' => '9855555555']);
        $student = Student::create([
            'user_id' => $user->id,
            'fullname' => 'Hari Bdr',
            'dob' => '2003-01-01',
            'contact' => '9855555555',
            'address' => 'Butwal',
        ]);

        Livewire::test(EditStudent::class, ['record' => $student->getRouteKey()])
            ->fillForm(['address' => 'Bhairahawa'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Bhairahawa', $student->refresh()->address);
    }
}
