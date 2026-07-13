<?php

namespace Modules\User\Services;

use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class UserAdministrationService
{
    public const PERMISSION = 'users.manage_roles';

    public function assertCanManage(User $actor, ?int $propertyId): void
    {
        abort_unless($actor->hasPermission(self::PERMISSION, $propertyId), 403);
    }

    public function isGlobalAdministrator(User $actor): bool
    {
        return $actor->hasPermission(self::PERMISSION);
    }

    public function assertCanManageTarget(User $actor, User $target, ?int $propertyId): void
    {
        $this->assertCanManage($actor, $propertyId);

        if ($propertyId !== null) {
            abort_unless(
                $target->roleAssignments()->where('property_id', $propertyId)->exists(),
                404,
            );
        }
    }

    public function createUser(User $actor, array $attributes): User
    {
        $propertyId = (int) $attributes['property_id'];
        $this->assertCanManage($actor, $propertyId);

        return DB::transaction(function () use ($actor, $attributes, $propertyId): User {
            $user = User::create([
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'password' => $attributes['password'],
            ]);
            $role = Role::where('slug', $attributes['role'])->firstOrFail();

            RoleAssignment::create([
                'user_id' => $user->id,
                'role_id' => $role->id,
                'property_id' => $propertyId,
                'assigned_by' => $actor->id,
            ]);

            Log::info('admin.user.created', [
                'actor_id' => $actor->id,
                'user_id' => $user->id,
                'property_id' => $propertyId,
                'role' => $role->slug,
            ]);

            return $user->load(['roleAssignments.role', 'roleAssignments.property']);
        });
    }

    public function setActiveStatus(User $actor, User $target, int $propertyId, bool $isActive): User
    {
        $this->assertCanManageTarget($actor, $target, $propertyId);

        if (! $this->isGlobalAdministrator($actor)) {
            $hasOtherAccess = $target->roleAssignments()
                ->where(fn ($query) => $query
                    ->whereNull('property_id')
                    ->orWhere('property_id', '!=', $propertyId))
                ->exists();

            abort_if($hasOtherAccess, 403);
        }

        if ($actor->is($target) && ! $isActive) {
            throw ValidationException::withMessages([
                'is_active' => ['You cannot deactivate your own account.'],
            ]);
        }

        return DB::transaction(function () use ($actor, $target, $propertyId, $isActive): User {
            $target->forceFill([
                'is_active' => $isActive,
                'deactivated_at' => $isActive ? null : now(),
                'deactivated_by' => $isActive ? null : $actor->id,
            ])->save();

            if (! $isActive) {
                $target->tokens()->delete();
            }

            Log::info('admin.user.status_changed', [
                'actor_id' => $actor->id,
                'user_id' => $target->id,
                'property_id' => $propertyId,
                'is_active' => $isActive,
            ]);

            return $target->refresh()->load(['roleAssignments.role', 'roleAssignments.property']);
        });
    }
}
