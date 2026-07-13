# Testing and CI seed strategy

The automated suite has three layers:

- Unit tests isolate calculation and gateway adapter behavior.
- Feature tests exercise one API capability with the real Laravel container and an in-memory SQLite database.
- End-to-end integration tests exercise complete cross-module workflows through HTTP routes. `FullStayLifecycleTest` covers availability, reservation, check-in, an in-stay room move, folio repricing, payment capture, checkout, housekeeping handoff, refund, and multi-property authorization boundaries.

CI should run the repository quality gate with `composer check`. The equivalent explicit commands are `php vendor/bin/pint --test` and `php vendor/bin/phpunit`. Tests use the `phpunit.xml` in-memory SQLite configuration, the synchronous queue, and array mail transport, so no persistent database, queue worker, SMTP server, or external Stripe account is required. Gateway responses are intercepted with Laravel's HTTP fake.

## Deterministic representative data

`Database\Seeders\StayLifecycleCiSeeder` supplies a small, idempotent multi-property inventory fixture:

- two properties with separate staff access;
- standard and deluxe room types at the primary property;
- clean rooms suitable for availability, check-in, and room-move scenarios;
- stable staff email addresses and property names exposed as seeder constants.

The seeder deliberately creates no date-bound reservations or payments. Each test creates those records relative to a frozen clock, avoiding failures as calendar time advances. Re-running the seeder updates the same natural keys rather than duplicating rows.

To prepare a disposable local or CI database manually:

```bash
php artisan migrate:fresh --force
php artisan db:seed --class=Database\Seeders\StayLifecycleCiSeeder --force
```

The seeded credentials are `ci.staff@example.com` / `ci-password` for the primary property and `ci.other@example.com` / `ci-password` for the isolated property. These values are test fixtures only and must not be used in a shared or production environment.
