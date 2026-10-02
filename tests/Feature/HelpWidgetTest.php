<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientType;
use App\Models\CompanySetting;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class HelpWidgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_help_box_shows_on_login_pages_and_the_client_portal_but_not_inside_staff_panels(): void
    {
        foreach (['client', 'student', 'team', 'admin'] as $panel) {
            $this->get(Filament::getPanel($panel)->getLoginUrl())->assertOk()->assertSee('Need help?')->assertSee('+977 9761008090');
        }

        $this->get(Filament::getPanel('team')->getRequestPasswordResetUrl())->assertSee('Need help?');
        $this->get('/')->assertSee('Need help?')->assertSee('https://wa.me/9779761008090', false);

        $clientUser = User::factory()->create(['phone' => '9811111111']);
        Client::create(['user_id' => $clientUser->id, 'contact_person' => 'Shishir', 'client_type_id' => ClientType::create(['name' => 'Individual', 'slug' => 'individual'])->id, 'contact' => '9811111111', 'address' => 'Belbari']);
        $this->actingAs($clientUser)->get('/client')->assertOk()->assertSee('Need help?');

        $admin = User::factory()->create(['phone' => '9800000000']);
        $admin->assignRole(Role::create(['name' => 'super_admin']));
        $this->actingAs($admin)->get('/admin')->assertOk()->assertDontSee('cx-help-card');
    }

    public function test_admin_can_change_or_hide_the_contacts(): void
    {
        CompanySetting::current()->update(['help_phone' => '+977 9800011122', 'help_email' => null, 'help_facebook' => 'https://facebook.com/civorax']);

        $this->get('/client/login')
            ->assertSee('+977 9800011122')
            ->assertDontSee('info@civoraxinfra.com')
            ->assertSee('https://facebook.com/civorax', false);

        CompanySetting::current()->update(['help_enabled' => false]);
        $this->get('/client/login')->assertDontSee('cx-help-card');
    }
}
