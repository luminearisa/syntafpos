<?php

namespace App\Http\Controllers\Api\V1\Roles;

use App\Http\Controllers\Controller;
use App\Http\Resources\PermissionResource;
use App\Models\Permission;
use App\Support\BusinessContext;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PermissionController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected BusinessContext $context
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->requirePermission('roles.view');

        $permissions = Permission::query()
            ->when($request->group, fn ($q, $group) => $q->where('group', $group))
            ->orderBy('group')
            ->orderBy('name')
            ->get();

        return $this->success(PermissionResource::collection($permissions), 'Success');
    }

    public function show(Permission $permission): JsonResponse
    {
        $this->requirePermission('roles.view');

        return $this->success(new PermissionResource($permission), 'Success');
    }
}
