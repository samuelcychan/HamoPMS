# Role-based access control

Roles are assigned globally or within one property. A global assignment applies to every property. A property assignment is accepted only when the request resolves to that property through a route parameter, request payload, query filter, booking, or payment.

## Seeded role matrix

| Role | Permissions |
| --- | --- |
| Admin | All seeded permissions, including role management |
| Manager | Property, room, reservation, and folio read/write; payment read |
| Receptionist | Property read; room, reservation, folio, and payment read/write |
| Housekeeping | Property read; room read/write |
| Accountant | Property and reservation read; folio and payment read/write |

The canonical permission slugs are `properties.read`, `properties.write`, `rooms.read`, `rooms.write`, `reservations.read`, `reservations.write`, `folios.read`, `folios.write`, `payments.read`, `payments.write`, and `users.manage_roles`.

Run `php artisan db:seed --class=RolePermissionSeeder` to update the matrix idempotently. The default `DatabaseSeeder` also runs this seeder.

## Enforcement

Protected routes use `permission:<slug>`. Failed checks return `403` with the standard `FORBIDDEN` API error envelope. Resource routes derive property scope from `propertyId`, `property_id`, booking context, or payment context. Collection routes without a property filter require a global role assignment.

## Admin assignment API

Users with `users.manage_roles` can inspect, create, and remove role assignments:

- `GET /api/v1/admin/users/{userId}/role-assignments`
- `POST /api/v1/admin/users/{userId}/role-assignments`
- `DELETE /api/v1/admin/users/{userId}/role-assignments/{assignmentId}`

The create payload contains a role slug and optional `property_id`. `assigned_by`, `created_at`, and `updated_at` provide the baseline assignment audit fields.

A global Admin role assignment is the super-admin capability. It can manage users and roles across all properties. A property-scoped Admin must include `property_id` and can manage only users and assignments in that property.

## Admin user API

- `GET /api/v1/admin/users?property_id={propertyId}`
- `POST /api/v1/admin/users`
- `GET /api/v1/admin/users/{userId}?property_id={propertyId}`
- `PATCH /api/v1/admin/users/{userId}/status`

Creating a user requires `name`, `email`, a confirmed `password`, `property_id`, and an initial role slug. Status changes require `property_id` and `is_active`. Deactivation revokes all API tokens. A scoped Admin cannot globally deactivate a user who also has access to another property.

User reads, creation, status changes, and role assignment changes write structured `admin.*` audit events with the actor, target user, and property context.
