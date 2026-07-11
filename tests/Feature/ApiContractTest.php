<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Property\Models\Property;
use Tests\TestCase;

class ApiContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_success_responses_use_data_envelope(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.version', 'v1');
    }

    public function test_validation_errors_use_unified_contract(): void
    {
        $this->postJson('/api/v1/auth/register', [])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['code', 'message', 'details' => ['fields' => ['name', 'email', 'password']]]]);
    }

    public function test_not_found_errors_use_unified_contract(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/properties/999999')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
    }

    public function test_paginated_responses_expose_pagination_metadata(): void
    {
        $user = User::factory()->create();
        Property::create(['name' => 'Hotel', 'address' => '1 Main St', 'type' => 'hotel']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/properties?per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.pagination.per_page', 1)
            ->assertJsonPath('meta.pagination.total', 1);
    }
}
