<?php

namespace Tests\Feature;

use App\Filament\Resources\ProjectResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * With PORTAL_URL set, the portals answer on app.civoraxinfra.com and the API stays on api.civoraxinfra.com.
 */
class PortalDomainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        // Panels pick up their address while the app boots, so set it before.
        putenv('PORTAL_URL=https://app.civoraxinfra.test');
        $_ENV['PORTAL_URL'] = $_SERVER['PORTAL_URL'] = 'https://app.civoraxinfra.test';

        parent::setUp();
    }

    protected function tearDown(): void
    {
        putenv('PORTAL_URL');
        unset($_ENV['PORTAL_URL'], $_SERVER['PORTAL_URL']);

        parent::tearDown();
    }

    public function test_portals_live_on_the_portal_address_and_old_links_redirect(): void
    {
        $this->get('https://app.civoraxinfra.test/team/login')->assertOk();
        $this->get('https://app.civoraxinfra.test/client/login')->assertOk();

        // Old address: same page on the new one (query string kept).
        $this->get('https://api.civoraxinfra.test/admin/login?x=1')
            ->assertStatus(301)
            ->assertRedirect('https://app.civoraxinfra.test/admin/login?x=1');
        $this->get('https://api.civoraxinfra.test/verify/ABC123')
            ->assertRedirect('https://app.civoraxinfra.test/verify/ABC123');

        // The API is not moved.
        $this->get('https://api.civoraxinfra.test/api/v1/blog/categories')->assertOk();

        // The bare portal address shows the CivoraX portal page, with links on the portal address.
        $this->get('https://app.civoraxinfra.test/')
            ->assertOk()
            ->assertSee('Every project, site and class')
            ->assertSee('https://app.civoraxinfra.test/client/login', false)
            ->assertSee('https://app.civoraxinfra.test/team/password-reset/request', false)
            ->assertDontSee('Laravel');

        // Links (e.g. in notifications sent by scheduled jobs) use the portal address.
        $this->assertStringStartsWith('https://app.civoraxinfra.test/admin/projects', ProjectResource::getUrl(panel: 'admin'));
        $this->assertStringStartsWith('https://app.civoraxinfra.test/site/app', route('site.app'));
    }
}
