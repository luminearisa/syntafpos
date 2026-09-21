<?php

namespace App\Http\Controllers\Api\V1\Roles;

use App\Http\Controllers\Controller;
use App\Http\Requests\Role\StoreRoleRequest;
use App\Http\Requests\Role\UpdateRoleRequest;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use App\Services\AuditService;
use App\Support\BusinessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected AuditService $audit,
        protected BusinessContext $context
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Role::class);

        $user = $request->user();
        $companyIds = $user->companies()->pluck('companies.id')->all();

        $roles = Role::query()
            ->where(fn ($q) => $q->whereIn('company_id', $companyIds)->orWhereNull('company_id'))
            ->when($request->search, fn ($q, $search) => $q->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('display_name', 'like', "%{$search}%");
            }))
            ->with('permissions')
            ->withCount('users')
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return $this->paginated(RoleResource::collection($roles));
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        $this->authorize('create', Role::class);

        $data = $request->validated();
        $companyId = $data['company_id'] ?? $this->context->companyId();

        $role = Role::create([
            'company_id' => $data['company_id'] ?? null,
            'name' => $data['name'],
            'display_name' => $data['display_name'] ?? $data['name'],
            'description' => $data['description'] ?? null,
        ]);

        if (! empty($data['permissions'])) {
            $role->syncPermissionNames($data['permissions']);
        }

        $role->load('permissions');

        $this->audit->record('role.create', 'role', $role->id, null, $request->validated(), $companyId);

        return $this->success(new RoleResource($role), 'Role created', 201);
    }

    public function show(Role $role): JsonResponse
    {
        $this->authorize('view', $role);

        return $this->success(new RoleResource($role->load('permissions')));
    }

    public function update(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        $this->authorize('update', $role);

        $role->update($request->only(['display_name', 'description']));

        if ($request->has('permissions')) {
            $role->syncPermissionNames($request->validated()['permissions']);
        }

        $role->load('permissions');

        $this->audit->record('role.update', 'role', $role->id, null, $request->validated(), $role->company_id);

        return $this->success(new RoleResource($role));
    }

    public function destroy(Role $role): JsonResponse
    {
        $this->authorize('delete', $role);

        if ($role->is_system) {
            throw new AuthorizationException('System roles cannot be deleted.');
        }

        if ($role->users()->exists()) {
            return $this->error('This role is still assigned to users. Reassign them first.', 422);
        }

        $role->delete();

        $this->audit->record('role.delete', 'role', $role->id, $role->toArray(), null, $role->company_id);

        return $this->success(null, 'Role deleted');
    }
}
