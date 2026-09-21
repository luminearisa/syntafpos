<?php

namespace App\Http\Controllers\Api\V1\Users;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Register;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AuditService;
use App\Support\BusinessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected AuditService $audit,
        protected BusinessContext $context
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $users = $this->scopedQuery($request)
            ->with(['companies', 'roles'])
            ->paginate($request->integer('per_page', 20));

        return $this->paginated(UserResource::collection($users));
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $this->authorize('create', User::class);

        $data = $request->validated();

        $user = DB::transaction(function () use ($data, $request) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'phone' => $data['phone'] ?? null,
                'status' => $data['status'] ?? 'active',
            ]);

            $this->syncBusinessAccess($user, $request);

            return $user;
        });

        $user->load(['companies', 'branches', 'warehouses', 'registers', 'roles']);

        $this->audit->record('user.create', 'user', $user->id, null, $request->validated(), $this->context->companyId());

        return $this->success(new UserResource($user), 'User created', 201);
    }

    public function show(User $user): JsonResponse
    {
        $this->authorize('view', $user);

        $user->load(['companies', 'branches', 'warehouses', 'registers', 'roles']);

        return $this->success(new UserResource($user));
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $data = $request->validated();
        $old = $user->only(array_keys($data));

        DB::transaction(function () use ($user, $data, $request) {
            $payload = $data;
            unset($payload['company_ids'], $payload['branch_ids'], $payload['warehouse_ids'], $payload['register_ids'], $payload['role_ids']);

            if (isset($payload['password']) && $payload['password'] === null) {
                unset($payload['password']);
            }

            $user->update($payload);

            if ($request->has('company_ids') || $request->has('branch_ids') || $request->has('warehouse_ids') || $request->has('register_ids') || $request->has('role_ids')) {
                $this->syncBusinessAccess($user, $request);
            }
        });

        $user->load(['companies', 'branches', 'warehouses', 'registers', 'roles']);

        $this->audit->record('user.update', 'user', $user->id, $old, $user->only(array_keys($old)), $this->context->companyId());

        return $this->success(new UserResource($user));
    }

    public function destroy(User $user): JsonResponse
    {
        $this->authorize('delete', $user);

        if ($user->email === 'admin@example.com') {
            throw ValidationException::withMessages([
                'user' => 'The system administrator account cannot be deleted.',
            ]);
        }

        $user->delete();

        $this->audit->record('user.delete', 'user', $user->id, $user->toArray(), null, $this->context->companyId());

        return $this->success(null, 'User deleted');
    }

    private function scopedQuery(Request $request)
    {
        $user = $request->user();
        $companyId = $request->integer('company_id') ?: $this->context->companyId();

        return User::query()
            ->when($companyId, fn ($q) => $q->whereHas('companies', fn ($q) => $q->where('companies.id', $companyId)))
            ->when($request->search, fn ($q, $search) => $q->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            }))
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->when($request->sort && in_array($request->sort, ['name', 'email', 'created_at']), fn ($q) => $q->orderBy($request->sort, $request->direction === 'asc' ? 'asc' : 'desc'), fn ($q) => $q->latest());
    }

    /**
     * Sync a user's business access grants, enforcing that every granted entity
     * sits inside a company the acting user can reach.
     */
    private function syncBusinessAccess(User $user, Request $request): void
    {
        $actor = $request->user();
        $companyId = $this->context->companyId();

        $allowedCompanies = $actor->companies()->pluck('companies.id')->all();
        $allowedCompanyIds = array_map('intval', $allowedCompanies);

        $companyIds = $request->has('company_ids') ? $request->collect('company_ids')->map(fn ($v) => (int) $v)->all() : array_values(array_map('intval', $user->companies()->pluck('companies.id')->all()));

        foreach ($companyIds as $id) {
            if (! in_array($id, $allowedCompanyIds, true)) {
                throw new AuthorizationException('You do not have access to company '.$id.'.');
            }
        }

        $user->companies()->sync($companyIds);

        if ($request->has('branch_ids')) {
            $branchIds = $request->collect('branch_ids')->map(fn ($v) => (int) $v)->all();

            $valid = Branch::query()
                ->whereIn('id', $branchIds)
                ->whereIn('company_id', $companyIds)
                ->pluck('id')
                ->map(fn ($v) => (int) $v)
                ->all();

            if (count($valid) !== count($branchIds)) {
                throw new AuthorizationException('One or more branches do not belong to the granted companies.');
            }

            $user->branches()->sync($branchIds);
        }

        if ($request->has('warehouse_ids')) {
            $warehouseIds = $request->collect('warehouse_ids')->map(fn ($v) => (int) $v)->all();

            $valid = Warehouse::query()
                ->whereIn('id', $warehouseIds)
                ->whereIn('company_id', $companyIds)
                ->pluck('id')
                ->map(fn ($v) => (int) $v)
                ->all();

            if (count($valid) !== count($warehouseIds)) {
                throw new AuthorizationException('One or more warehouses do not belong to the granted companies.');
            }

            $user->warehouses()->sync($warehouseIds);
        }

        if ($request->has('register_ids')) {
            $registerIds = $request->collect('register_ids')->map(fn ($v) => (int) $v)->all();

            $valid = Register::query()
                ->whereIn('id', $registerIds)
                ->whereIn('company_id', $companyIds)
                ->pluck('id')
                ->map(fn ($v) => (int) $v)
                ->all();

            if (count($valid) !== count($registerIds)) {
                throw new AuthorizationException('One or more registers do not belong to the granted companies.');
            }

            $user->registers()->sync($registerIds);
        }

        if ($request->has('role_ids')) {
            $roleIds = $request->collect('role_ids')->map(fn ($v) => (int) $v)->all();

            $valid = Role::query()
                ->whereIn('id', $roleIds)
                ->where(function ($q) use ($companyIds) {
                    $q->whereIn('company_id', $companyIds)->orWhereNull('company_id');
                })
                ->pluck('id')
                ->map(fn ($v) => (int) $v)
                ->all();

            if (count($valid) !== count($roleIds)) {
                throw new AuthorizationException('One or more roles are not available for the granted companies.');
            }

            $user->roles()->sync($roleIds);
        }

        $user->clearPermissionCache($companyId);
    }
}
