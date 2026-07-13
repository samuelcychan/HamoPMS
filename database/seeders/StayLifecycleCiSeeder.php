<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Database\Seeder;
use Modules\Property\Models\Property;
use Modules\Property\Models\Room;

class StayLifecycleCiSeeder extends Seeder
{
    public const PRIMARY_PROPERTY = 'CI Harbour Hotel';

    public const OTHER_PROPERTY = 'CI Mountain Hotel';

    public const STAFF_EMAIL = 'ci.staff@example.com';

    public const OTHER_STAFF_EMAIL = 'ci.other@example.com';

    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);
        $primary = Property::updateOrCreate(
            ['name' => self::PRIMARY_PROPERTY],
            ['address' => '1 CI Harbour Road', 'type' => 'hotel'],
        );
        $other = Property::updateOrCreate(
            ['name' => self::OTHER_PROPERTY],
            ['address' => '2 CI Mountain Road', 'type' => 'hotel'],
        );
        $standard = $primary->roomTypes()->updateOrCreate(
            ['code' => 'STD'],
            [
                'name' => 'CI Standard Room',
                'max_occupancy' => 2,
                'base_rate' => '100.00',
                'is_active' => true,
            ],
        );
        $deluxe = $primary->roomTypes()->updateOrCreate(
            ['code' => 'DLX'],
            [
                'name' => 'CI Deluxe Room',
                'max_occupancy' => 3,
                'base_rate' => '150.00',
                'is_active' => true,
            ],
        );
        $otherStandard = $other->roomTypes()->updateOrCreate(
            ['code' => 'STD'],
            [
                'name' => 'CI Other Standard Room',
                'max_occupancy' => 2,
                'base_rate' => '120.00',
                'is_active' => true,
            ],
        );

        foreach ([
            [$primary, $standard, '101'],
            [$primary, $standard, '102'],
            [$primary, $deluxe, '201'],
            [$other, $otherStandard, '901'],
        ] as [$property, $roomType, $number]) {
            $property->rooms()->updateOrCreate(
                ['number' => $number],
                [
                    'room_type_id' => $roomType->id,
                    'status' => Room::STATUS_CLEAN,
                ],
            );
        }

        $staff = User::updateOrCreate(
            ['email' => self::STAFF_EMAIL],
            ['name' => 'CI Front Desk', 'password' => 'ci-password'],
        );
        $otherStaff = User::updateOrCreate(
            ['email' => self::OTHER_STAFF_EMAIL],
            ['name' => 'CI Other Front Desk', 'password' => 'ci-password'],
        );
        $receptionistRoleId = Role::where('slug', 'receptionist')->value('id');

        RoleAssignment::updateOrCreate([
            'user_id' => $staff->id,
            'role_id' => $receptionistRoleId,
            'property_id' => $primary->id,
        ]);
        RoleAssignment::updateOrCreate([
            'user_id' => $otherStaff->id,
            'role_id' => $receptionistRoleId,
            'property_id' => $other->id,
        ]);
    }
}
