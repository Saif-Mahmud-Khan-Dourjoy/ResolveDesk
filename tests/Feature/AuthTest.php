<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Login, logout, rate limiting and inactive users.
 * Run only this file:  php artisan test --filter=AuthTest
 */
class AuthTest extends TestCase
{
    use RefreshDatabase; // fresh tables for every test (in resolvedesk_testing)

    public function test_login_page_is_shown_to_guests(): void
    {
        $this->get('/login')->assertOk()->assertSee('Sign in to your account');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_user_can_log_in_with_correct_credentials(): void
    {
        $user = User::factory()->customer()->create(['email' => 'john@example.com']);

        $response = $this->post('/login', ['email' => 'john@example.com', 'password' => 'password']);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        User::factory()->create(['email' => 'john@example.com']);

        $response = $this->from('/login')->post('/login', ['email' => 'john@example.com', 'password' => 'wrong-password']);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        User::factory()->inactive()->create(['email' => 'old@example.com']);

        $response = $this->post('/login', ['email' => 'old@example.com', 'password' => 'password']);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_user_deactivated_during_session_is_logged_out(): void
    {
        $user = User::factory()->agent()->inactive()->create();

        // actingAs() simulates an existing logged-in session.
        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_deactivated_user_gets_401_json_from_ajax_endpoints(): void
    {
        $user = User::factory()->inactive()->create();

        $this->actingAs($user)->getJson('/notifications/feed')->assertStatus(401);
    }

    public function test_login_is_rate_limited_after_five_failed_attempts(): void
    {
        User::factory()->create(['email' => 'john@example.com']);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post('/login', ['email' => 'john@example.com', 'password' => 'wrong']);
        }

        // Even the correct password is refused while locked out.
        $response = $this->post('/login', ['email' => 'john@example.com', 'password' => 'password']);

        $response->assertSessionHasErrors('email');
        $this->assertStringContainsString('Too many login attempts', session('errors')->first('email'));
        $this->assertGuest();
    }

    public function test_user_can_log_out(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/logout')->assertRedirect(route('login'));

        $this->assertGuest();
    }
}
