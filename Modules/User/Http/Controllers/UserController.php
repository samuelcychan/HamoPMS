<?php

namespace Modules\User\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json($request->user());
    }

    public function show(Request $request, string $id): JsonResponse
    {
        abort_if((string) $request->user()->id !== $id, 404);
        $user = $request->user();

        return response()->json($user);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        abort_if((string) $request->user()->id !== $id, 404);
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'string', 'email', 'max:255', 'unique:users,email,'.$user->id],
        ]);

        $user->update($validated);

        return response()->json($user);
    }
}
