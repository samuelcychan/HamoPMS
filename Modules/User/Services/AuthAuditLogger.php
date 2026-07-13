<?php

namespace Modules\User\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Modules\User\Models\AuthAuditEvent;

class AuthAuditLogger
{
    public function record(
        Request $request,
        string $event,
        ?User $user = null,
        ?string $email = null,
        ?array $metadata = null,
    ): AuthAuditEvent {
        return AuthAuditEvent::create([
            'user_id' => $user?->id,
            'email' => $email ?? $user?->email,
            'event' => $event,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent() === null
                ? null
                : mb_substr($request->userAgent(), 0, 1000),
            'metadata' => $metadata,
        ]);
    }
}
