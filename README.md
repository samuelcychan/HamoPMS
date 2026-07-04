# HamoPMS

**HamoPMS** is an open-source, API-first hotel Property Management System (PMS) built with **PHP / Laravel**.

It is inspired by [TelivityAI/HAIP](https://github.com/TelivityAI/haip) (a NestJS/TypeScript reference implementation), reimagined on top of Laravel's ecosystem: Eloquent, Sanctum, queues, and a multi-tenant, `property_id`-scoped data model designed for portfolio operators managing multiple hotels.

See [`BACKLOG.md`](BACKLOG.md) for the full Epic → Feature → Task breakdown of the project roadmap.

## Status

This repository currently contains the **Phase 0 foundation**:

- Laravel 13 application skeleton
- Multi-tenant data model (`properties` table, `property_id` scoping via `BelongsToProperty` trait + `PropertyContext`)
- Local RBAC (roles & permissions) via `spatie/laravel-permission`, team-scoped per property, seeded with a starter permission catalog and system roles (`admin`, `front_desk`, `housekeeping`, `revenue_manager`)
- API authentication via `laravel/sanctum`
- Versioned REST API structure (`/api/v1`) with a health-check endpoint
- Webhook/event engine skeleton (`webhook_subscriptions`, `webhook_deliveries`, queued delivery job with HMAC signing)
- CI workflow (lint + test) in `.github/workflows/ci.yml`

All other modules (reservations, rooms, rate plans, folios, housekeeping, night audit, channel manager, AI agents, etc.) are tracked as future work in `BACKLOG.md`.

## Getting Started

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite   # default local/dev DB is SQLite
php artisan migrate
php artisan db:seed
php artisan serve
```

The seeded demo property is `DEMO` with an admin user `admin@example.com` (see `database/seeders/DatabaseSeeder.php`).

### Multi-tenancy

Every tenant-scoped model uses the `App\Support\Tenancy\BelongsToProperty` trait, which:

- Applies a global query scope constraining rows to the current `App\Support\Tenancy\PropertyContext`.
- Auto-fills `property_id` on create.
- Exposes a `property()` relation and a `withoutPropertyScope()` local scope for cross-property queries.

The `App\Http\Middleware\ResolvePropertyContext` middleware resolves the active property per API request, from the `X-Property-Id` header or the authenticated user's home property, and is applied to all `api/*` routes.

### Roles & Permissions

Permissions are managed via `spatie/laravel-permission` with team support enabled (`config/permission.php`, team key = `property_id`), so a user's roles/permissions are scoped per property. The code-defined permission catalog and starter roles live in `database/seeders/PermissionSeeder.php`.

### Webhooks

Modules dispatch domain events via `App\Services\Webhooks\WebhookDispatcher::dispatch('reservation.created', $propertyId, $payload)`. Active subscriptions listening for that event type are notified asynchronously (`App\Jobs\DeliverWebhook`), with HMAC-SHA256 request signing and delivery logging.

## Tech Stack

| Layer | Technology |
|---|---|
| Language | PHP 8.3+ |
| Framework | Laravel 13 |
| Database | PostgreSQL (production) / SQLite (local dev, CI) |
| Auth | Laravel Sanctum (API tokens) |
| Authorization | spatie/laravel-permission (team-scoped RBAC) |
| Queue | Database/Redis queue driver (Laravel Queues) |
| API Docs | OpenAPI (via `dedoc/scramble`) |

## Testing & Linting

```bash
php artisan test
vendor/bin/pint --test
```
