<?php

namespace Database\Factories;

use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Property>
 */
class PropertyFactory extends Factory
{
    protected $model = Property::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company().' Hotel',
            'code' => strtoupper(fake()->unique()->lexify('????')),
            'timezone' => 'UTC',
            'currency' => 'USD',
            'city' => fake()->city(),
            'country' => fake()->countryCode(),
            'is_active' => true,
        ];
    }
}
