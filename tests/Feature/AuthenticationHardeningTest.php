<?php

namespace Tests\Feature;

use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuthenticationHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('login');
    }

    public function test_a_wrong_password_does_not_confirm_the_account_exists(): void
    {
        User::factory()->create(['email' => 'known@example.com', 'password' => 'correct-password']);

        $this->postJson('/api/login', [
            'email' => 'known@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(422)->assertJsonValidationErrors('email');

        $this->postJson('/api/login', [
            'email' => 'nobody@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_a_disabled_account_is_indistinguishable_from_a_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'disabled@example.com',
            'password' => 'correct-password',
            'is_active' => false,
        ]);

        $this->postJson('/api/login', [
            'email' => 'disabled@example.com',
            'password' => 'correct-password',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', LoginRequest::INVALID_CREDENTIALS);

        $this->assertGuest();
    }

    public function test_a_disabled_account_does_not_receive_a_session(): void
    {
        User::factory()->create([
            'email' => 'disabled@example.com',
            'password' => 'correct-password',
            'is_active' => false,
        ]);

        $this->postJson('/api/login', [
            'email' => 'disabled@example.com',
            'password' => 'correct-password',
        ])->assertStatus(422);

        $this->assertGuest();
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_repeated_failed_logins_are_throttled(): void
    {
        User::factory()->create(['email' => 'known@example.com', 'password' => 'correct-password']);

        foreach (range(1, 6) as $ignored) {
            $this->postJson('/api/login', [
                'email' => 'known@example.com',
                'password' => 'wrong-password',
            ]);
        }

        $response = $this->postJson('/api/login', [
            'email' => 'known@example.com',
            'password' => 'correct-password',
        ]);

        $response->assertStatus(429);
    }

    public function test_the_health_endpoint_is_public(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonStructure(['status', 'checks' => ['database', 'storage']]);
    }

    public function test_the_health_endpoint_does_not_leak_internals(): void
    {
        $body = $this->getJson('/api/health')->assertOk()->content();

        $this->assertStringNotContainsString(\App::version(), $body);
        $this->assertStringNotContainsString(config('app.name'), $body);
        $this->assertStringNotContainsString('mysql', strtolower($body));
    }
}
