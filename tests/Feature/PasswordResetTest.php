<?php

namespace Tests\Feature;

use App\Filament\Auth\RequestPasswordResetByPhone;
use App\Models\Client;
use App\Models\ClientType;
use App\Models\User;
use App\Notifications\PortalPasswordReset;
use Filament\Facades\Filament;
use Filament\Pages\Auth\PasswordReset\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected User $clientUser;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->clientUser = User::factory()->create(['phone' => '9811111111', 'email' => 'shishir@example.com', 'name' => 'Shishir Sharma']);
        Client::create([
            'user_id' => $this->clientUser->id, 'contact_person' => 'Shishir Sharma',
            'client_type_id' => ClientType::create(['name' => 'Individual', 'slug' => 'individual'])->id,
            'contact' => '9811111111', 'address' => 'Belbari',
        ]);

        Filament::setCurrentPanel(Filament::getPanel('client'));
    }

    public function test_login_page_links_to_forgot_password(): void
    {
        $this->get(Filament::getPanel('client')->getLoginUrl())->assertOk()->assertSee(Filament::getPanel('client')->getRequestPasswordResetUrl(), false);
    }

    public function test_a_client_resets_the_password_with_the_emailed_link(): void
    {
        // Typed with the country code and spaces.
        Livewire::test(RequestPasswordResetByPhone::class)
            ->fillForm(['login' => '+977 981-1111111'])
            ->call('request')
            ->assertNotified('Check your email');

        $url = null;
        Notification::assertSentTo($this->clientUser, PortalPasswordReset::class, function (PortalPasswordReset $notification) use (&$url): bool {
            $url = $notification->url;

            return str_contains($url, '/client/password-reset/reset') && $notification->portal === 'client';
        });

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        Livewire::withQueryParams($query)
            ->test(ResetPassword::class, ['email' => $query['email'], 'token' => $query['token']])
            ->fillForm(['password' => 'new-secret-123', 'passwordConfirmation' => 'new-secret-123'])
            ->call('resetPassword')
            ->assertHasNoFormErrors();

        $this->assertTrue(Hash::check('new-secret-123', $this->clientUser->fresh()->password));
    }

    public function test_nothing_is_sent_without_an_email_or_for_another_portal_and_the_answer_is_the_same(): void
    {
        $noEmail = User::factory()->create(['phone' => '9822222222', 'email' => null]);
        Client::create(['user_id' => $noEmail->id, 'contact_person' => 'Ram', 'client_type_id' => ClientType::first()->id, 'contact' => '9822222222', 'address' => 'Itahari']);

        foreach (['9822222222', '9800000099', 'nobody@example.com'] as $login) {
            Livewire::test(RequestPasswordResetByPhone::class)
                ->fillForm(['login' => $login])
                ->call('request')
                ->assertNotified('Check your email');
        }

        // A client asking on the student portal gets no link (it would not let them in there).
        Filament::setCurrentPanel(Filament::getPanel('student'));
        Livewire::test(RequestPasswordResetByPhone::class)
            ->fillForm(['login' => 'shishir@example.com'])
            ->call('request');

        Notification::assertNothingSent();
    }
}
