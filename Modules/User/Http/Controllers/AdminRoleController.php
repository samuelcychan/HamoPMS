<?php

namespace Modules\User\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Modules\User\Services\UserAdministrationService;

class AdminRoleController extends Controller
{
    public function __construct(private readonly UserAdministrationService $administration) {}

    public function index(Request $request, string $userId): JsonResponse
    {
        $user = User::findOrFail($userId);
        $validated = $request->validate([
            'property_id' => ['nullable', 'integer', 'exists:properties,id'],
        ]);
        $propertyId = isset($validated['property_id']) ? (int) $validated['property_id'] : null;
        $this->administration->assertCanManage($request->user(), $propertyId);

        Log::info('admin.role_assignments.listed', [
            'actor_id' => $request->user()->id,
            'user_id' => $user->id,
            'property_id' => $propertyId,
        ]);

        return ApiResponse::success(
            $user->roleAssignments()
                ->with(['role.permissions', 'property'])
                ->when(
                    array_key_exists('property_id', $validated),
                    fn ($query) => $query->where('property_id', $validated['property_id']),
                )
                ->get(),
        );
    }

    public function store(Request $request, string $userId): JsonResponse
    {
        $user = User::findOrFail($userId);
        $validated = $request->validate([
            'role' => ['required', 'string', 'exists:roles,slug'],
            'property_id' => ['nullable', 'integer', 'exists:properties,id'],
        ]);
        $propertyId = isset($validated['property_id']) ? (int) $validated['property_id'] : null;
        $this->administration->assertCanManage($request->user(), $propertyId);
        $role = Role::where('slug', $validated['role'])->firstOrFail();
        $assignment = RoleAssignment::firstOrCreate(
            [
                'user_id' => $user->id,
                'role_id' => $role->id,
                'property_id' => $validated['property_id'] ?? null,
            ],
            ['assigned_by' => $request->user()->id],
        );

        Log::info('admin.role_assignment.saved', [
            'actor_id' => $request->user()->id,
            'user_id' => $user->id,
            'assignment_id' => $assignment->id,
            'property_id' => $propertyId,
            'role' => $role->slug,
            'created' => $assignment->wasRecentlyCreated,
        ]);

        return ApiResponse::success(
            $assignment->load(['role.permissions', 'property']),
            $assignment->wasRecentlyCreated ? 201 : 200,
        );
    }

    public function destroy(Request $request, string $userId, string $assignmentId): Response
    {
        User::findOrFail($userId);
        $assignment = RoleAssignment::query()
            ->where('user_id', $userId)
            ->findOrFail($assignmentId);
        $this->administration->assertCanManage(
            $request->user(),
            $assignment->property_id === null ? null : (int) $assignment->property_id,
        );
        $audit = [
            'actor_id' => $request->user()->id,
            'user_id' => (int) $userId,
            'assignment_id' => $assignment->id,
            'property_id' => $assignment->property_id,
            'role_id' => $assignment->role_id,
        ];
        $assignment->delete();

        Log::info('admin.role_assignment.removed', $audit);

        return response()->noContent();
    }
}
