<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    public const MATRIX = [
        'admin' => [
            'properties.read', 'properties.write',
            'rooms.read', 'rooms.write',
            'reservations.read', 'reservations.write',
            'folios.read', 'folios.write', 'folios.adjust',
            'payments.read', 'payments.write',
            'users.manage_roles',
        ],
        'manager' => [
            'properties.read', 'properties.write',
            'rooms.read', 'rooms.write',
            'reservations.read', 'reservations.write',
            'folios.read', 'folios.write', 'folios.adjust',
            'payments.read',
        ],
        'receptionist' => [
            'properties.read',
            'rooms.read', 'rooms.write',
            'reservations.read', 'reservations.write',
            'folios.read', 'folios.write',
            'payments.read', 'payments.write',
        ],
        'housekeeping' => [
            'properties.read',
            'rooms.read', 'rooms.write',
        ],
        'accountant' => [
            'properties.read',
            'reservations.read',
            'folios.read', 'folios.write', 'folios.adjust',
            'payments.read', 'payments.write',
        ],
    ];

    public function run(): void
    {
        $permissions = collect(self::MATRIX)
            ->flatten()
            ->unique()
            ->mapWithKeys(function (string $slug): array {
                $permission = Permission::updateOrCreate(
                    ['slug' => $slug],
                    ['name' => str($slug)->replace('.', ' ')->title()->toString()],
                );

                return [$slug => $permission->id];
            });

        foreach (self::MATRIX as $slug => $rolePermissions) {
            $role = Role::updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => str($slug)->replace('_', ' ')->title()->toString(),
                    'description' => "Seeded {$slug} role.",
                ],
            );

            $role->permissions()->sync(
                collect($rolePermissions)->map(fn (string $permission) => $permissions[$permission]),
            );
        }
    }
}
