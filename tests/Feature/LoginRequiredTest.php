<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The public deployment: with DASHBOARD_REQUIRE_LOGIN every dashboard page sends a guest to the login, and the one
 * account written by `dashboard:admin` from .env gets in (RequireLogin reads the setting on each request).
 */
class LoginRequiredTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'dashboard.require_login' => true,
            'dashboard.admin.email' => 'owner@example.com',
            'dashboard.admin.password' => 'a-long-test-password',
        ]);
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
