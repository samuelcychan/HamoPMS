# HamoPMS — Property Management System API

A Laravel 12 API-first backend for the Hamo Property Management System.

---

## Table of Contents

- [Requirements](#requirements)
- [Project Structure](#project-structure)
- [Quick Start (Local)](#quick-start-local)
- [Environment Strategy](#environment-strategy)
- [API Routing](#api-routing)
- [API Contract](#api-contract)
- [Domain Modules](#domain-modules)
- [Running Tests](#running-tests)

---

## Requirements

| Tool       | Version  |
|------------|----------|
| PHP        | ≥ 8.2    |
| Composer   | ≥ 2.x    |
| SQLite     | any      |
| PostgreSQL | ≥ 15 (staging/prod) |

---

## Project Structure

```
HamoPMS/
├── app/                        # Core Laravel application code
│   ├── Http/Controllers/       # Base controller
│   ├── Models/                 # Core models (User)
│   └── Providers/              # Service providers
├── bootstrap/                  # Application bootstrap
├── config/                     # Configuration files
├── database/
│   ├── factories/              # Model factories
│   ├── migrations/             # Database migrations
│   └── seeders/                # Database seeders
├── Modules/                    # Domain modules (DDD structure)
│   ├── Booking/
│   │   ├── Http/Controllers/
│   │   ├── Models/
│   ├── Payment/
│   ├── Property/
│   └── User/
├── public/                     # Web root
├── routes/
│   ├── api.php                 # API route entrypoint
│   ├── api/v1/                 # Versioned API routes
│   │   ├── auth.php
│   │   ├── bookings.php
│   │   ├── payments.php
│   │   ├── properties.php
│   │   └── users.php
│   └── console.php
├── storage/
├── tests/
│   ├── Feature/
│   └── Unit/
├── .env.example                # Base env template
├── .env.dev                    # Dev environment template
├── .env.staging                # Staging environment template (no secrets)
├── .env.production             # Production environment template (no secrets)
└── phpunit.xml
```

---

## Quick Start (Local)

```bash
# 1. Clone the repository
git clone https://github.com/samuelcychan/HamoPMS.git
cd HamoPMS

# 2. Install PHP dependencies
composer install

# 3. Copy dev environment file
cp .env.dev .env

# 4. Generate application key
php artisan key:generate

# 5. Create the SQLite database file
touch database/database.sqlite

# 6. Run migrations
php artisan migrate

# 7. Start the development server
php artisan serve
```

The API will be available at **http://localhost:8000/api**.

Verify the service is running:

```bash
curl http://localhost:8000/api/health
# {"status":"ok","service":"HamoPMS","version":"v1"}
```

---

## Environment Strategy

HamoPMS uses per-environment `.env.*` template files committed to the repository. **No real secrets are ever committed.**

| File | Purpose | Secrets in repo? |
|------|---------|-----------------|
| `.env.example` | Canonical template with all keys documented | ✅ No values |
| `.env.dev` | Local development defaults (SQLite, log mail) | ✅ Safe defaults |
| `.env.staging` | Staging template; secrets injected by CI/CD | ❌ Placeholders only |
| `.env.production` | Production template; secrets injected by CI/CD | ❌ Placeholders only |
| `.env` | **Never committed** — your local active config | — |

### Environment differences

| Setting | Dev | Staging | Production |
|---------|-----|---------|-----------|
| `APP_DEBUG` | `true` | `false` | `false` |
| `DB_CONNECTION` | `sqlite` | `pgsql` | `pgsql` |
| `CACHE_STORE` | `database` | `redis` | `redis` |
| `QUEUE_CONNECTION` | `database` | `redis` | `redis` |
| `SESSION_DRIVER` | `database` | `redis` | `redis` |
| `LOG_LEVEL` | `debug` | `info` | `warning` |

---

## API Routing

All routes are prefixed with `/api`. Versioned routes live under `/api/v1/`.

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/health` | Health check |
| `POST` | `/api/v1/auth/register` | Register a new user |
| `POST` | `/api/v1/auth/login` | Login and receive token |
| `POST` | `/api/v1/auth/logout` | Revoke current token |
| `GET` | `/api/v1/auth/me` | Get authenticated user |
| `GET` | `/api/v1/users` | List users |
| `GET/PUT` | `/api/v1/users/{id}` | Get / update user |
| `GET/POST` | `/api/v1/properties` | List / create properties |
| `GET/PUT/DELETE` | `/api/v1/properties/{id}` | Get / update / delete property |
| `GET/POST` | `/api/v1/bookings` | List / create bookings |
| `GET/PUT/DELETE` | `/api/v1/bookings/{id}` | Get / update / delete booking |
| `GET/POST` | `/api/v1/payments` | List / create payments |
| `GET` | `/api/v1/payments/{id}` | Get payment details |
| `GET` | `/api/v1/bookings/{booking_id}/folio` | Get / auto-create folio for a booking |
| `POST` | `/api/v1/bookings/{booking_id}/folio/line-items` | Post a charge or tax entry to folio |

All endpoints except `/api/health`, `/api/v1/auth/register`, and `/api/v1/auth/login` require a `Bearer` token from Sanctum.

## API Contract

API success, error, validation, pagination, filtering, and versioning conventions are defined in [the API contract](docs/api-contract.md). The current public baseline is `/api/v1`.

---

## Domain Modules

The codebase is organised into vertical domain slices under `Modules/`. Each module owns its controllers and models.

| Module | Responsibility |
|--------|---------------|
| `User` | Authentication, user profile management |
| `Property` | Property listings and management |
| `Booking` | Reservation lifecycle |
| `Payment` | Payment processing and records |
| `Folio` | Guest folio ledger — immutable room-charge and tax postings |

Module namespaces are auto-loaded via `composer.json`:

```json
"autoload": {
    "psr-4": {
        "Modules\\": "Modules/"
    }
}
```

---

## Running Tests

```bash
# Run all tests
php artisan test

# Run only unit tests
php artisan test --testsuite=Unit

# Run only feature tests
php artisan test --testsuite=Feature

# Run with coverage (requires Xdebug or PCOV)
php artisan test --coverage
```

Tests use an **in-memory SQLite** database (`DB_DATABASE=:memory:`) configured in `phpunit.xml`, so no separate test database setup is needed.

The end-to-end coverage model and deterministic multi-property CI seed strategy are documented in [the testing strategy](docs/testing-strategy.md).

Release candidates must also complete the operator scenarios, sign-off gates, rollback rehearsal, and hypercare preparation in the [UAT and go-live readiness runbook](docs/uat-go-live-readiness.md).
