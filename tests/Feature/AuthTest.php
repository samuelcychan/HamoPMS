<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['user', 'token']);
    }

    public function test_user_can_login(): void
    {
        $user = User::factory()->create([
            'email' => 'john@example.com',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['user', 'token']);
    }

    public function test_authenticated_user_can_get_profile(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me');

        $response->assertOk()
            ->assertJson(['id' => $user->id]);
    }

    public function test_authenticated_user_can_logout(): void
    {
        $user = User::factory()->create();
        $accessToken = $user->createToken('test-token');
        $token = $accessToken->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/logout');

        $response->assertOk()
            ->assertJson(['message' => 'Logged out successfully.']);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $accessToken->accessToken->id,
        ]);

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }

    public function test_login_endpoint_is_rate_limited(): void
    {
        foreach (range(1, 5) as $_) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'missing@example.com',
                'password' => 'password',
            ])->assertStatus(422);
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'missing@example.com',
            'password' => 'password',
        ])->assertTooManyRequests();
    }

    public function test_register_endpoint_is_rate_limited(): void
    {
        foreach (range(1, 5) as $_) {
            $this->postJson('/api/v1/auth/register', [
                'name' => 'John Doe',
            ])->assertStatus(422);
        }

        $this->postJson('/api/v1/auth/register', [
            'name' => 'John Doe',
        ])->assertTooManyRequests();
    }

    public function test_logout_endpoint_is_rate_limited(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 5) as $attempt) {
            $token = $user->createToken('test-token-'.$attempt)->plainTextToken;

            $this->withHeader('Authorization', 'Bearer '.$token)
                ->postJson('/api/v1/auth/logout')
                ->assertOk();
        }

        $token = $user->createToken('test-token-6')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/logout')
            ->assertTooManyRequests();
    }

    public function test_auth_events_are_written_to_the_audit_log(): void
    {
        Log::spy();
        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated();

        Log::shouldHaveReceived('info')
            ->with('auth.register', ['user_id' => User::where('email', 'jane@example.com')->value('id')])
            ->once();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        Log::shouldHaveReceived('info')
            ->with('auth.login', ['user_id' => $user->id])
            ->once();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'missing@example.com',
            'password' => 'password',
        ])->assertStatus(422);

        Log::shouldHaveReceived('warning')
            ->with('auth.login_failed', [
                'email' => 'missing@example.com',
                'ip' => '127.0.0.1',
            ])
            ->once();

        $accessToken = $user->createToken('test-token');
        $token = $accessToken->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        Log::shouldHaveReceived('info')
            ->with('auth.logout', [
                'user_id' => $user->id,
                'token_id' => $accessToken->accessToken->id,
            ])
            ->once();
    }
}
