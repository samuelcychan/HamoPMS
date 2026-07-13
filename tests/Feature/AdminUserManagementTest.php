<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Modules\Property\Models\Property;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $globalAdmin;

    private User $propertyAdmin;

    private Property $firstProperty;

    private Property $secondProperty;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->firstProperty = $this->createProperty('First Hotel');
        $this->secondProperty = $this->createProperty('Second Hotel');
        $this->globalAdmin = User::factory()->create();
        $this->propertyAdmin = User::factory()->create();
        $this->assign($this->globalAdmin, 'admin');
        $this->assign($this->propertyAdmin, 'admin', $this->firstProperty);
    }

    public function test_global_admin_can_create_list_and_view_users_in_any_property(): void
    {
        Log::spy();

        $response = $this->actingAs($this->globalAdmin, 'sanctum')
            ->postJson('/api/v1/admin/users', $this->createPayload($this->secondProperty))
            ->assertCreated()
            ->assertJsonPath('data.email', 'new.user@example.com')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.role_assignments.0.role.slug', 'receptionist')
            ->assertJsonPath('data.role_assignments.0.property.id', $this->secondProperty->id);

        $userId = $response->json('data.id');
        $user = User::findOrFail($userId);
        $this->assertTrue(Hash::check('StrongPassword1!', $user->password));

        Log::shouldHaveReceived('info')->with('admin.user.created', [
            'actor_id' => $this->globalAdmin->id,
            'user_id' => $user->id,
            'property_id' => $this->secondProperty->id,
            'role' => 'receptionist',
        ])->once();

        $this->actingAs($this->globalAdmin, 'sanctum')
            ->getJson("/api/v1/admin/users?property_id={$this->secondProperty->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $user->id)
            ->assertJsonPath('meta.pagination.total', 1);

        $this->actingAs($this->globalAdmin, 'sanctum')
            ->getJson("/api/v1/admin/users/{$user->id}?property_id={$this->secondProperty->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);

        Log::shouldHaveReceived('info')->with('admin.user.listed', [
            'actor_id' => $this->globalAdmin->id,
            'property_id' => $this->secondProperty->id,
        ])->once();
        Log::shouldHaveReceived('info')->with('admin.user.viewed', [
            'actor_id' => $this->globalAdmin->id,
            'user_id' => $user->id,
            'property_id' => $this->secondProperty->id,
        ])->once();
    }

    public function test_property_admin_is_limited_to_their_property(): void
    {
        $this->actingAs($this->propertyAdmin, 'sanctum')
            ->postJson('/api/v1/admin/users', $this->createPayload($this->firstProperty))
            ->assertCreated();

        $this->actingAs($this->propertyAdmin, 'sanctum')
            ->postJson('/api/v1/admin/users', [
                ...$this->createPayload($this->secondProperty),
                'email' => 'denied@example.com',
            ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');

        $this->actingAs($this->propertyAdmin, 'sanctum')
            ->getJson('/api/v1/admin/users')
            ->assertForbidden();
    }

    public function test_deactivation_revokes_tokens_and_blocks_inactive_accounts(): void
    {
        Log::spy();
        $target = User::factory()->create(['email' => 'target@example.com']);
        $this->assign($target, 'receptionist', $this->firstProperty);
        $target->createToken('active-token');

        $this->actingAs($this->propertyAdmin, 'sanctum')
            ->patchJson("/api/v1/admin/users/{$target->id}/status", [
                'property_id' => $this->firstProperty->id,
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.deactivated_by', $this->propertyAdmin->id);

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'is_active' => false,
            'deactivated_by' => $this->propertyAdmin->id,
        ]);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $target->id]);

        Log::shouldHaveReceived('info')->with('admin.user.status_changed', [
            'actor_id' => $this->propertyAdmin->id,
            'user_id' => $target->id,
            'property_id' => $this->firstProperty->id,
            'is_active' => false,
        ])->once();

        $this->actingAs($target->refresh(), 'sanctum')
            ->getJson('/api/v1/auth/me')
            ->assertForbidden()
            ->assertExactJson([
                'error' => [
                    'code' => 'ACCOUNT_INACTIVE',
                    'message' => 'This account is inactive.',
                ],
            ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $target->email,
            'password' => 'password',
        ])->assertUnprocessable();

        $this->actingAs($this->propertyAdmin, 'sanctum')
            ->patchJson("/api/v1/admin/users/{$target->id}/status", [
                'property_id' => $this->firstProperty->id,
                'is_active' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.deactivated_at', null)
            ->assertJsonPath('data.deactivated_by', null);
    }

    public function test_property_admin_cannot_deactivate_a_multi_property_user(): void
    {
        $target = User::factory()->create();
        $this->assign($target, 'receptionist', $this->firstProperty);
        $this->assign($target, 'receptionist', $this->secondProperty);

        $this->actingAs($this->propertyAdmin, 'sanctum')
            ->patchJson("/api/v1/admin/users/{$target->id}/status", [
                'property_id' => $this->firstProperty->id,
                'is_active' => false,
            ])
            ->assertForbidden();

        $this->assertTrue($target->fresh()->is_active);
    }

    public function test_role_assignments_are_property_scoped_and_audit_logged(): void
    {
        Log::spy();
        $target = User::factory()->create();
        $this->assign($target, 'receptionist', $this->firstProperty);

        $response = $this->actingAs($this->globalAdmin, 'sanctum')
            ->postJson("/api/v1/admin/users/{$target->id}/role-assignments", [
                'role' => 'manager',
                'property_id' => $this->secondProperty->id,
            ])
            ->assertCreated();

        $assignmentId = $response->json('data.id');
        Log::shouldHaveReceived('info')->with('admin.role_assignment.saved', [
            'actor_id' => $this->globalAdmin->id,
            'user_id' => $target->id,
            'assignment_id' => $assignmentId,
            'property_id' => $this->secondProperty->id,
            'role' => 'manager',
            'created' => true,
        ])->once();

        $this->actingAs($this->propertyAdmin, 'sanctum')
            ->deleteJson("/api/v1/admin/users/{$target->id}/role-assignments/{$assignmentId}")
            ->assertForbidden();

        $this->actingAs($this->globalAdmin, 'sanctum')
            ->deleteJson("/api/v1/admin/users/{$target->id}/role-assignments/{$assignmentId}")
            ->assertNoContent();

        Log::shouldHaveReceived('info')->with('admin.role_assignment.removed', [
            'actor_id' => $this->globalAdmin->id,
            'user_id' => $target->id,
            'assignment_id' => $assignmentId,
            'property_id' => $this->secondProperty->id,
            'role_id' => Role::where('slug', 'manager')->value('id'),
        ])->once();
    }

    public function test_admin_cannot_deactivate_their_own_account(): void
    {
        $this->actingAs($this->propertyAdmin, 'sanctum')
            ->patchJson("/api/v1/admin/users/{$this->propertyAdmin->id}/status", [
                'property_id' => $this->firstProperty->id,
                'is_active' => false,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    private function createPayload(Property $property): array
    {
        return [
            'name' => 'New User',
            'email' => 'new.user@example.com',
            'password' => 'StrongPassword1!',
            'password_confirmation' => 'StrongPassword1!',
            'property_id' => $property->id,
            'role' => 'receptionist',
        ];
    }

    private function assign(User $user, string $role, ?Property $property = null): RoleAssignment
    {
        return RoleAssignment::create([
            'user_id' => $user->id,
            'role_id' => Role::where('slug', $role)->value('id'),
            'property_id' => $property?->id,
        ]);
    }

    private function createProperty(string $name): Property
    {
        return Property::create([
            'name' => $name,
            'address' => '1 Main Street',
            'type' => 'hotel',
        ]);
    }
}
