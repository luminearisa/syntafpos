<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditService;
use App\Support\BusinessContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        protected AuditService $audit,
        protected BusinessContext $context
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => __('The provided credentials are incorrect.'),
            ]);
        }

        if ($user->status !== 'active') {
            throw ValidationException::withMessages([
                'email' => __('This account is not active. Please contact an administrator.'),
            ]);
        }

        $user->update(['last_login_at' => now()]);
        $user->load(['companies', 'branches.warehouses', 'warehouses', 'registers', 'roles']);

        $token = $user->createToken(
            $request->device_name ?? 'web-'.uniqid()
        )->plainTextToken;

        $this->audit->record('login', 'user', $user->id, null, [
            'last_login_at' => now()->toIso8601String(),
        ], $user->companies()->first()?->id, $user->id);

        return $this->success([
            'token' => $token,
            'user' => new UserResource($user),
        ], 'Login successful');
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        // Revoke the presented token so it cannot be replayed.
        $user->tokens()->where('id', $user->currentAccessToken()?->getKey())->delete();

        $this->audit->record('logout', 'user', $user->id, null, null, $user->companies()->first()?->id, $user->id);

        return $this->success(null, 'Logout successful');
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->load(['companies', 'branches.warehouses', 'warehouses', 'registers', 'roles']);

        $user->permissions = $user->permissionNames($user->companies()->first()?->id);

        return $this->success(['user' => new UserResource($user)], 'Success');
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! Hash::check($request->current_password, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => __('The current password is incorrect.'),
            ]);
        }

        $user->update(['password' => $request->password]);

        $this->audit->record('password.change', 'user', $user->id, null, null, $this->context->companyId(), $user->id);

        return $this->success(null, 'Password updated successfully');
    }
}
