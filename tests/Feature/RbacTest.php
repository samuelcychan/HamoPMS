<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Property\Models\Property;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_initial_role_matrix_is_seeded(): void
    {
        $this->assertDatabaseCount('roles', 5);

        foreach (RolePermissionSeeder::MATRIX as $roleSlug => $permissions) {
            $role = Role::where('slug', $roleSlug)->firstOrFail();

            $this->assertEqualsCanonicalizing(
                $permissions,
                $role->permissions()->pluck('slug')->all(),
            );
        }
    }

    public function test_property_scoped_role_only_grants_access_to_its_property(): void
    {
        $user = User::factory()->create();
        $allowed = $this->createProperty('Allowed Hotel');
        $denied = $this->createProperty('Denied Hotel');
        $this->assign($user, 'manager', $allowed);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/properties/{$allowed->id}/room-types")
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/properties/{$denied->id}/room-types")
            ->assertForbidden()
            ->assertExactJson([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'You are not allowed to perform this action.',
                ],
            ]);
    }

    public function test_role_permissions_are_enforced_by_core_api_routes(): void
    {
        $housekeeper = User::factory()->create();
        $property = $this->createProperty();
        $this->assign($housekeeper, 'housekeeping', $property);

        $this->actingAs($housekeeper, 'sanctum')
            ->getJson("/api/v1/properties/{$property->id}")
            ->assertOk();

        $this->actingAs($housekeeper, 'sanctum')
            ->putJson("/api/v1/properties/{$property->id}", ['name' => 'Changed'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_user_without_a_role_receives_standard_forbidden_contract(): void
    {
        $user = User::factory()->create();
        $property = $this->createProperty();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/properties/{$property->id}/rooms")
            ->assertForbidden()
            ->assertExactJson([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'You are not allowed to perform this action.',
                ],
            ]);
    }

    public function test_global_admin_can_assign_list_and_remove_property_role(): void
    {
        $admin = User::factory()->create();
        $target = User::factory()->create();
        $property = $this->createProperty();
        $this->assign($admin, 'admin');

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/users/{$target->id}/role-assignments", [
                'role' => 'manager',
                'property_id' => $property->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.role.slug', 'manager')
            ->assertJsonPath('data.property.id', $property->id)
            ->assertJsonPath('data.assigned_by', $admin->id);

        $assignmentId = $response->json('data.id');

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/admin/users/{$target->id}/role-assignments")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/admin/users/{$target->id}/role-assignments/{$assignmentId}")
            ->assertNoContent();

        $this->assertDatabaseMissing('role_assignments', ['id' => $assignmentId]);
    }

    public function test_non_admin_cannot_use_role_assignment_api(): void
    {
        $manager = User::factory()->create();
        $target = User::factory()->create();
        $this->assign($manager, 'manager');

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/admin/users/{$target->id}/role-assignments", [
                'role' => 'manager',
            ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    private function assign(User $user, string $roleSlug, ?Property $property = null): RoleAssignment
    {
        return RoleAssignment::create([
            'user_id' => $user->id,
            'role_id' => Role::where('slug', $roleSlug)->value('id'),
            'property_id' => $property?->id,
        ]);
    }

    private function createProperty(string $name = 'Hotel'): Property
    {
        return Property::create([
            'name' => $name,
            'address' => '1 Main Street',
            'type' => 'hotel',
        ]);
    }
}
