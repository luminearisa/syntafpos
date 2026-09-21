<?php

namespace App\Http\Controllers\Api\V1\Companies;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\StoreCompanyRequest;
use App\Http\Requests\Company\UpdateCompanyRequest;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use App\Services\AuditService;
use App\Support\BusinessContext;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CompanyController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected AuditService $audit,
        protected BusinessContext $context
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Company::class);

        $companies = $request->user()
            ->companies()
            ->when($request->search, fn ($q, $search) => $q->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('legal_name', 'like', "%{$search}%");
            }))
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->when($request->sort && in_array($request->sort, ['name', 'code', 'created_at']), fn ($q) => $q->orderBy($request->sort, $request->direction === 'asc' ? 'asc' : 'desc'), fn ($q) => $q->latest())
            ->withCount(['branches', 'warehouses', 'registers'])
            ->paginate($request->integer('per_page', 20));

        return $this->paginated(CompanyResource::collection($companies));
    }

    public function store(StoreCompanyRequest $request): JsonResponse
    {
        $this->authorize('create', Company::class);

        $data = $request->validated();

        $company = Company::create($data);

        $request->user()->companies()->attach($company->id);

        $this->audit->record('company.create', 'company', $company->id, null, $data, $company->id);

        return $this->success(new CompanyResource($company), 'Company created', 201);
    }

    public function show(Company $company): JsonResponse
    {
        $this->authorize('view', $company);

        $company->loadCount(['branches', 'warehouses', 'registers']);

        return $this->success(new CompanyResource($company));
    }

    public function update(UpdateCompanyRequest $request, Company $company): JsonResponse
    {
        $this->authorize('update', $company);

        $old = $company->only(array_keys($request->validated()));

        $company->update($request->validated());

        $this->audit->record('company.update', 'company', $company->id, $old, $company->fresh()->only(array_keys($old)), $company->id);

        return $this->success(new CompanyResource($company->fresh()));
    }

    public function destroy(Company $company): JsonResponse
    {
        $this->authorize('delete', $company);

        if ($company->branches()->exists() || $company->warehouses()->exists()) {
            throw ValidationException::withMessages([
                'company' => 'Cannot delete a company that still has branches or warehouses. Archive it instead.',
            ]);
        }

        $company->delete();

        $this->audit->record('company.delete', 'company', $company->id, $company->toArray(), null, $company->id);

        return $this->success(null, 'Company deleted');
    }
}
