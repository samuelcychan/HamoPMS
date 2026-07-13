<?php

namespace Modules\User\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Modules\User\Services\UserAdministrationService;

class AdminUserController extends Controller
{
    public function __construct(private readonly UserAdministrationService $administration) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'property_id' => ['nullable', 'integer', 'exists:properties,id'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $propertyId = isset($validated['property_id']) ? (int) $validated['property_id'] : null;
        $this->administration->assertCanManage($request->user(), $propertyId);

        $users = $this->query($propertyId)
            ->paginate($request->integer('per_page', 15));

        Log::info('admin.user.listed', [
            'actor_id' => $request->user()->id,
            'property_id' => $propertyId,
        ]);

        return ApiResponse::paginated($users);
    }

    public function store(Request $request): JsonResponse
    {
        $request->merge(['email' => Str::lower((string) $request->input('email'))]);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => [
                'required',
                'confirmed',
                PasswordRule::min(12)->mixedCase()->numbers()->symbols(),
            ],
            'property_id' => ['required', 'integer', 'exists:properties,id'],
            'role' => ['required', 'string', Rule::exists('roles', 'slug')],
        ]);

        return ApiResponse::success(
            $this->administration->createUser($request->user(), $validated),
            201,
        );
    }

    public function show(Request $request, string $userId): JsonResponse
    {
        $validated = $request->validate([
            'property_id' => ['nullable', 'integer', 'exists:properties,id'],
        ]);
        $propertyId = isset($validated['property_id']) ? (int) $validated['property_id'] : null;
        $user = User::findOrFail($userId);
        $this->administration->assertCanManageTarget($request->user(), $user, $propertyId);
        $user = $this->query($propertyId)->findOrFail($userId);

        Log::info('admin.user.viewed', [
            'actor_id' => $request->user()->id,
            'user_id' => $user->id,
            'property_id' => $propertyId,
        ]);

        return ApiResponse::success($user);
    }

    public function updateStatus(Request $request, string $userId): JsonResponse
    {
        $validated = $request->validate([
            'property_id' => ['required', 'integer', 'exists:properties,id'],
            'is_active' => ['required', 'boolean'],
        ]);

        return ApiResponse::success(
            $this->administration->setActiveStatus(
                $request->user(),
                User::findOrFail($userId),
                (int) $validated['property_id'],
                (bool) $validated['is_active'],
            ),
        );
    }

    private function query(?int $propertyId): Builder
    {
        return User::query()
            ->when(
                $propertyId !== null,
                fn (Builder $query) => $query->whereHas(
                    'roleAssignments',
                    fn (Builder $assignments) => $assignments->where('property_id', $propertyId),
                ),
            )
            ->with(['roleAssignments' => fn ($query) => $query
                ->when($propertyId !== null, fn ($assignments) => $assignments->where('property_id', $propertyId))
                ->with(['role', 'property'])])
            ->orderBy('id');
    }
}
