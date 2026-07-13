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

class AdminRoleController extends Controller
{
    public function index(Request $request, string $userId): JsonResponse
    {
        $user = User::findOrFail($userId);
        $validated = $request->validate([
            'property_id' => ['nullable', 'integer', 'exists:properties,id'],
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
        $role = Role::where('slug', $validated['role'])->firstOrFail();
        $assignment = RoleAssignment::firstOrCreate(
            [
                'user_id' => $user->id,
                'role_id' => $role->id,
                'property_id' => $validated['property_id'] ?? null,
            ],
            ['assigned_by' => $request->user()->id],
        );

        return ApiResponse::success(
            $assignment->load(['role.permissions', 'property']),
            $assignment->wasRecentlyCreated ? 201 : 200,
        );
    }

    public function destroy(string $userId, string $assignmentId): Response
    {
        User::findOrFail($userId);
        $assignment = RoleAssignment::query()
            ->where('user_id', $userId)
            ->findOrFail($assignmentId);
        $assignment->delete();

        return response()->noContent();
    }
}
