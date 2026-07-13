<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Modules\Property\Models\Property;
use RuntimeException;
use Tests\TestCase;

class ApiContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

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
        $this->grantAdmin($user);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/properties/999999')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
    }

    public function test_paginated_responses_expose_pagination_metadata(): void
    {
        $user = User::factory()->create();
        $this->grantAdmin($user);
        Property::create(['name' => 'Hotel', 'address' => '1 Main St', 'type' => 'hotel']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/properties?per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.pagination.per_page', 1)
            ->assertJsonPath('meta.pagination.total', 1);
    }

    public function test_delete_responses_have_no_content(): void
    {
        $user = User::factory()->create();
        $this->grantAdmin($user);
        $property = Property::create(['name' => 'Hotel', 'address' => '1 Main St', 'type' => 'hotel']);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/properties/{$property->id}")
            ->assertNoContent();

        $this->assertSoftDeleted($property);
    }

    public function test_unexpected_api_errors_use_unified_contract(): void
    {
        Route::get('/api/v1/contract/unexpected-error', function () {
            throw new RuntimeException('Sensitive implementation detail');
        });

        $this->getJson('/api/v1/contract/unexpected-error')
            ->assertInternalServerError()
            ->assertExactJson([
                'error' => [
                    'code' => 'HTTP_ERROR',
                    'message' => 'The request could not be completed.',
                ],
            ]);
    }

    private function grantAdmin(User $user): void
    {
        RoleAssignment::create([
            'user_id' => $user->id,
            'role_id' => Role::where('slug', 'admin')->value('id'),
        ]);
    }
}
