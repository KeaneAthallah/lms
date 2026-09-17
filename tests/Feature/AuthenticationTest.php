<?php

namespace Tests\Feature;

use App\Models\User;
use App\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_creates_a_student_and_logs_in(): void
    {
        $response = $this->post('/api/register', [
            'name' => 'Jane Student',
            'email' => 'jane@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.email', 'jane@example.com')
            ->assertJsonPath('message', 'Your account has been created.');

        $this->assertDatabaseHas('users', ['email' => 'jane@example.com']);
        $this->assertTrue(User::where('email', 'jane@example.com')->first()->hasRole(Role::Student));
        $this->assertAuthenticated();
    }

    public function test_login_with_valid_credentials_succeeds(): void
    {
        $user = User::factory()->student()->create();

        $this->post('/api/login', [
            'email' => $user->email,
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('data.email', $user->email);

        $this->assertAuthenticated();
    }

    public function test_login_with_invalid_credentials_is_rejected(): void
    {
        User::factory()->student()->create(['email' => 'sam@example.com']);

        $this->post('/api/login', [
            'email' => 'sam@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(422);

        $this->assertGuest();
    }

    public function test_disabled_users_cannot_login(): void
    {
        $user = User::factory()->student()->create(['is_active' => false]);

        $this->post('/api/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertStatus(422);

        $this->assertGuest();
    }

    public function test_me_returns_the_authenticated_user(): void
    {
        $user = User::factory()->student()->create();

        $this->actingAs($user)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.roles.0', 'student');
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/me')->assertStatus(401);
    }

    public function test_logout_invalidates_the_session(): void
    {
        $user = User::factory()->student()->create();

        $this->actingAs($user)
            ->post('/api/logout')
            ->assertOk()
            ->assertJsonPath('message', 'You have been signed out.');

        $this->assertGuest();
    }
}
