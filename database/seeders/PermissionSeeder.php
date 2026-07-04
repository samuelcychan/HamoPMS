<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the code-defined permission catalog and a set of built-in system
 * roles, mirroring HAIP's approach: permissions map 1:1 to API
 * capabilities / dashboard nav items, and operators can additionally
 * create custom roles via the permission matrix in Settings.
 */
class PermissionSeeder extends Seeder
{
    /**
     * The code-defined permission catalog. Grouped by module for
     * readability; stored flat (dot-notation) like HAIP's
     * `reservations.write`, `rooms.read`, etc.
     */
    protected array $permissions = [
        'reservations' => ['read', 'write', 'cancel'],
        'rooms' => ['read', 'write'],
        'rates' => ['read', 'write'],
        'guests' => ['read', 'write'],
        'folios' => ['read', 'write', 'settle'],
        'housekeeping' => ['read', 'manage'],
        'night_audit' => ['run', 'read'],
        'reports' => ['read'],
        'channels' => ['read', 'manage'],
        'media' => ['read', 'manage'],
        'payments' => ['read', 'process'],
        'admin' => ['users.manage', 'roles.manage', 'settings.manage'],
    ];

    /**
     * Built-in system roles and the permission groups granted in full.
     * These mirror HAIP's Keycloak roles (admin, front_desk,
     * housekeeping, revenue_manager) but are enforced locally here.
     */
    protected array $roles = [
        'admin' => ['*'],
        'front_desk' => ['reservations', 'rooms', 'guests', 'folios', 'payments'],
        'housekeeping' => ['housekeeping', 'rooms.read'],
        'revenue_manager' => ['rates', 'reports', 'night_audit'],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $allPermissionNames = [];

        foreach ($this->permissions as $module => $actions) {
            foreach ($actions as $action) {
                $name = "{$module}.{$action}";
                Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
                $allPermissionNames[] = $name;
            }
        }

        foreach ($this->roles as $roleName => $grants) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);

            if ($grants === ['*']) {
                $role->syncPermissions($allPermissionNames);

                continue;
            }

            $permissionNames = collect($allPermissionNames)->filter(
                fn (string $permission) => collect($grants)->contains(
                    fn (string $grant) => $permission === $grant || str_starts_with($permission, "{$grant}.")
                )
            )->values()->all();

            $role->syncPermissions($permissionNames);
        }
    }
}
