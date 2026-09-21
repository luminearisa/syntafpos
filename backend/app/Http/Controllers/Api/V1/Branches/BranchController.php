<?php

namespace App\Http\Controllers\Api\V1\Branches;

use App\Http\Controllers\Controller;
use App\Http\Requests\Branch\StoreBranchRequest;
use App\Http\Requests\Branch\UpdateBranchRequest;
use App\Http\Resources\BranchResource;
use App\Models\Branch;
use App\Services\AuditService;
use App\Support\BusinessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected AuditService $audit,
        protected BusinessContext $context
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Branch::class);

        $branches = $this->scopedQuery($request)
            ->withCount(['warehouses', 'registers'])
            ->paginate($request->integer('per_page', 20));

        return $this->paginated(BranchResource::collection($branches));
    }

    public function store(StoreBranchRequest $request): JsonResponse
    {
        $this->authorize('create', Branch::class);

        $this->ensureCompanyAccess($request->company_id);

        $branch = Branch::create($request->validated());

        $this->audit->record('branch.create', 'branch', $branch->id, null, $request->validated(), $branch->company_id);

        return $this->success(new BranchResource($branch), 'Branch created', 201);
    }

    public function show(Branch $branch): JsonResponse
    {
        $this->authorize('view', $branch);

        $branch->loadCount(['warehouses', 'registers']);

        return $this->success(new BranchResource($branch));
    }

    public function update(UpdateBranchRequest $request, Branch $branch): JsonResponse
    {
        $this->authorize('update', $branch);

        $old = $branch->only(array_keys($request->validated()));

        $branch->update($request->validated());

        $this->audit->record('branch.update', 'branch', $branch->id, $old, $branch->fresh()->only(array_keys($old)), $branch->company_id);

        return $this->success(new BranchResource($branch->fresh()));
    }

    public function destroy(Branch $branch): JsonResponse
    {
        $this->authorize('delete', $branch);

        $branch->delete();

        $this->audit->record('branch.delete', 'branch', $branch->id, $branch->toArray(), null, $branch->company_id);

        return $this->success(null, 'Branch deleted');
    }

    private function scopedQuery(Request $request)
    {
        $user = $request->user();
        $companyId = $request->integer('company_id') ?: $this->context->companyId();

        return Branch::query()
            ->visibleTo($user)
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($request->search, fn ($q, $search) => $q->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            }))
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->when($request->type, fn ($q, $type) => $q->where('type', $type))
            ->when($request->sort && in_array($request->sort, ['name', 'code', 'created_at']), fn ($q) => $q->orderBy($request->sort, $request->direction === 'asc' ? 'asc' : 'desc'), fn ($q) => $q->latest());
    }

    /**
     * The acting user must be able to reach the company they are mutating.
     */
    private function ensureCompanyAccess(int $companyId): void
    {
        $user = request()->user();

        if (! $user->companies()->where('companies.id', $companyId)->exists()) {
            throw new AuthorizationException('You do not have access to this company.');
        }
    }
}
