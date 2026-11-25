<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The public deployment: with DASHBOARD_REQUIRE_LOGIN every dashboard page sends a guest to the login, and the one
 * account written by `dashboard:admin` from .env gets in. The route group reads the setting when the routes load, so it is
 * set in the environment before the application boots.
 */
class LoginRequiredTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        foreach (['DASHBOARD_REQUIRE_LOGIN' => 'true', 'DASHBOARD_ADMIN_EMAIL' => 'owner@example.com', 'DASHBOARD_ADMIN_PASSWORD' => 'a-long-test-password'] as $k => $v) {
            putenv("$k=$v");
            $_ENV[$k] = $_SERVER[$k] = $v;
        }
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['DASHBOARD_REQUIRE_LOGIN', 'DASHBOARD_ADMIN_EMAIL', 'DASHBOARD_ADMIN_PASSWORD'] as $k) {
            putenv($k);
            unset($_ENV[$k], $_SERVER[$k]);
        }
    }

    public function test_guests_are_sent_to_the_login(): void
    {
        foreach (['/', '/soma', '/soma/board', '/soma/hotkeys', '/sn34'] as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
    }

    public function test_the_admin_account_from_env_gets_in(): void
    {
        $this->artisan('dashboard:admin')->assertSuccessful();
        $user = User::where('email', 'owner@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('a-long-test-password', $user->password));
        $this->assertNotNull($user->email_verified_at);

        $this->post(route('login.store'), ['email' => 'owner@example.com', 'password' => 'wrong-password'])->assertSessionHasErrors();
        $this->assertGuest();
        $this->post(route('login.store'), ['email' => 'owner@example.com', 'password' => 'a-long-test-password']);
        $this->assertAuthenticatedAs($user);
        $this->get('/')->assertOk();
    }
}
