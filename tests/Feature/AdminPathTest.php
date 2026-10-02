<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADMIN_PATH moves the admin panel to an address only the owners know; /admin then does not exist.
 */
class AdminPathTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        // Panels pick up their address while the app boots, so set it before.
        putenv('ADMIN_PATH=dheeraj');
        $_ENV['ADMIN_PATH'] = $_SERVER['ADMIN_PATH'] = 'dheeraj';

        parent::setUp();
    }

    protected function tearDown(): void
    {
        putenv('ADMIN_PATH=admin');
        $_ENV['ADMIN_PATH'] = $_SERVER['ADMIN_PATH'] = 'admin';

        parent::tearDown();
    }

    public function test_admin_panel_answers_only_on_its_secret_path_and_is_not_linked(): void
    {
        $this->get('/dheeraj/login')->assertOk();
        $this->get('/admin/login')->assertNotFound();
        $this->get('/admin')->assertNotFound();

        $this->get('/')->assertOk()->assertDontSee('dheeraj')->assertDontSee('>Admin<', false);
    }
}
