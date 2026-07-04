<?php

namespace Database\Seeders;

use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(PermissionSeeder::class);

        $property = Property::firstOrCreate(
            ['code' => 'DEMO'],
            ['name' => 'Demo Hotel', 'timezone' => 'UTC', 'currency' => 'USD']
        );

        $admin = User::factory()->create([
            'name' => 'Demo Admin',
            'email' => 'admin@example.com',
            'property_id' => $property->id,
        ]);

        $admin->assignRole('admin');
    }
}
