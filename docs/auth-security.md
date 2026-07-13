# Authentication security

New and reset passwords require at least 12 characters with uppercase and lowercase letters, a number, and a symbol. The same rule applies to public registration and administrator-created accounts.

`POST /api/v1/auth/forgot-password` always returns the same success message, whether or not the email exists. Active accounts receive Laravel's signed reset notification. `POST /api/v1/auth/reset-password` accepts the email, broker token, password, and confirmation; a successful reset rotates the remember token and revokes all existing Sanctum tokens.

Login failures are tracked by normalized email address. After `AUTH_LOGIN_MAX_ATTEMPTS` failures, further attempts are blocked for `AUTH_LOGIN_DECAY_SECONDS`, including attempts from a different source IP. The route-level IP limiter remains in place as a separate abuse control. Successful login clears the account lockout counter.

Security-sensitive actions are persisted in `auth_audit_events` with the related user when known, normalized email, event type, IP address, user agent, metadata, and immutable creation time. Events cover registration, successful and failed login, lockout, logout, password-reset request, failed reset, and completed reset. The reset-request response remains enumeration-safe even though the internal audit record distinguishes known users.
