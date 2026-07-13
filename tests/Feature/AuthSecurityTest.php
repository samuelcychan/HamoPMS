<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Modules\User\Models\AuthAuditEvent;
use Tests\TestCase;

class AuthSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_passwords_must_satisfy_the_security_policy(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Weak Password',
            'email' => 'weak@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['password']]]]);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Strong Password',
            'email' => 'Strong@Example.COM',
            'password' => 'StrongPassword1!',
            'password_confirmation' => 'StrongPassword1!',
        ])->assertCreated();
        $this->assertDatabaseHas('users', ['email' => 'strong@example.com']);
    }

    public function test_password_reset_is_enumeration_safe_audited_and_revokes_tokens(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'reset@example.com']);
        $accessToken = $user->createToken('existing-session')->accessToken;
        $resetToken = null;

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])
            ->assertOk()
            ->assertJsonPath('data.message', 'If that account exists, a password reset link has been sent.');
        Notification::assertSentTo(
            $user,
            ResetPassword::class,
            function (ResetPassword $notification) use (&$resetToken): bool {
                $resetToken = $notification->token;

                return true;
            },
        );
        $this->assertDatabaseHas('auth_audit_events', [
            'user_id' => $user->id,
            'email' => $user->email,
            'event' => 'password_reset_requested',
            'ip_address' => '127.0.0.1',
        ]);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $resetToken,
            'password' => 'Replacement2@Secure',
            'password_confirmation' => 'Replacement2@Secure',
        ])
            ->assertOk()
            ->assertJsonPath('data.message', 'Password reset successfully.');

        $this->assertTrue(Hash::check('Replacement2@Secure', $user->fresh()->password));
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $accessToken->id]);
        $this->assertDatabaseHas('auth_audit_events', [
            'user_id' => $user->id,
            'event' => 'password_reset_completed',
        ]);

        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'unknown@example.com'])
            ->assertOk();
        $this->assertSame(
            'If that account exists, a password reset link has been sent.',
            $unknown->json('data.message'),
        );
        $this->assertDatabaseHas('auth_audit_events', [
            'user_id' => null,
            'email' => 'unknown@example.com',
            'event' => 'password_reset_requested',
        ]);
    }

    public function test_account_lockout_is_email_scoped_across_source_addresses(): void
    {
        config()->set('auth.login_lockout.max_attempts', 3);
        config()->set('auth.login_lockout.decay_seconds', 600);
        $user = User::factory()->create(['email' => 'locked@example.com']);

        foreach (range(1, 3) as $attempt) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
                ->postJson('/api/v1/auth/login', [
                    'email' => strtoupper($user->email),
                    'password' => "wrong-password-{$attempt}",
                ])
                ->assertUnprocessable();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
            ->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'password',
            ])
            ->assertTooManyRequests()
            ->assertJsonPath('error.code', 'RATE_LIMIT_EXCEEDED');

        $this->assertSame(3, AuthAuditEvent::where('event', 'login_failed')->count());
        $this->assertDatabaseHas('auth_audit_events', [
            'user_id' => null,
            'email' => $user->email,
            'event' => 'login_locked',
            'ip_address' => '10.0.0.2',
        ]);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_and_logout_create_queryable_audit_records(): void
    {
        $user = User::factory()->create(['email' => 'audit@example.com']);
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])
            ->assertOk()
            ->json('data.token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->assertSame(
            ['login_succeeded', 'logout'],
            AuthAuditEvent::where('user_id', $user->id)->orderBy('id')->pluck('event')->all(),
        );
    }
}
