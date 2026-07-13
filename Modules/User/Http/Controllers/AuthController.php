<?php

namespace Modules\User\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Modules\User\Services\AuthAuditLogger;

class AuthController extends Controller
{
    public function __construct(private readonly AuthAuditLogger $audit) {}

    public function register(Request $request): JsonResponse
    {
        $request->merge(['email' => Str::lower((string) $request->input('email'))]);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'confirmed', $this->passwordRule()],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        $token = $user->createToken('api')->plainTextToken;

        Log::info('auth.register', ['user_id' => $user->id]);
        $this->audit->record($request, 'registered', $user);

        return ApiResponse::success([
            'user' => $user,
            'token' => $token,
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ]);
        $email = Str::lower($validated['email']);
        $lockoutKey = 'auth-login:'.hash('sha256', $email);

        if (RateLimiter::tooManyAttempts($lockoutKey, $this->maxLoginAttempts())) {
            $this->audit->record($request, 'login_locked', email: $email, metadata: [
                'retry_after_seconds' => RateLimiter::availableIn($lockoutKey),
            ]);
            abort(429);
        }

        $user = User::where('email', $email)->first();

        if (! $user || ! $user->is_active || ! Hash::check($validated['password'], $user->password)) {
            RateLimiter::hit($lockoutKey, $this->loginDecaySeconds());
            Log::warning('auth.login_failed', [
                'email' => $email,
                'ip' => $request->ip(),
            ]);
            $this->audit->record($request, 'login_failed', $user, $email, [
                'attempts' => RateLimiter::attempts($lockoutKey),
                'inactive' => $user !== null && ! $user->is_active,
            ]);

            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        RateLimiter::clear($lockoutKey);
        $token = $user->createToken('api')->plainTextToken;

        Log::info('auth.login', ['user_id' => $user->id]);
        $this->audit->record($request, 'login_succeeded', $user);

        return ApiResponse::success([
            'user' => $user,
            'token' => $token,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user->currentAccessToken();

        Log::info('auth.logout', ['user_id' => $user->id, 'token_id' => $token?->id]);
        $this->audit->record($request, 'logout', $user, metadata: ['token_id' => $token?->id]);

        if ($token) {
            $token->delete();
        }

        return ApiResponse::success(['message' => 'Logged out successfully.']);
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success($request->user());
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);
        $email = Str::lower($validated['email']);
        $user = User::query()->where('email', $email)->where('is_active', true)->first();
        $this->audit->record($request, 'password_reset_requested', $user, $email);

        if ($user !== null) {
            Password::sendResetLink(['email' => $email]);
        }

        return ApiResponse::success([
            'message' => 'If that account exists, a password reset link has been sent.',
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'token' => ['required', 'string'],
            'password' => ['required', 'confirmed', $this->passwordRule()],
        ]);
        $validated['email'] = Str::lower($validated['email']);
        $user = User::where('email', $validated['email'])->first();
        $status = Password::reset(
            $validated,
            function (User $resetUser, string $password): void {
                if (! $resetUser->is_active) {
                    throw ValidationException::withMessages([
                        'email' => ['This account is not active.'],
                    ]);
                }

                $resetUser->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();
                $resetUser->tokens()->delete();
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            $this->audit->record($request, 'password_reset_failed', $user, $validated['email'], [
                'broker_status' => $status,
            ]);
            throw ValidationException::withMessages([
                'token' => ['The password reset token is invalid or expired.'],
            ]);
        }

        $this->audit->record($request, 'password_reset_completed', $user, $validated['email']);

        return ApiResponse::success(['message' => 'Password reset successfully.']);
    }

    private function passwordRule(): PasswordRule
    {
        return PasswordRule::min(12)->mixedCase()->numbers()->symbols();
    }

    private function maxLoginAttempts(): int
    {
        return max(1, (int) config('auth.login_lockout.max_attempts', 5));
    }

    private function loginDecaySeconds(): int
    {
        return max(1, (int) config('auth.login_lockout.decay_seconds', 900));
    }
}
