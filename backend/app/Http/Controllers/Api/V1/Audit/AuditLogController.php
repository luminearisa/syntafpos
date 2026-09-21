<?php

namespace App\Http\Controllers\Api\V1\Audit;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Support\BusinessContext;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    use AuthorizesRequests;

    public function __construct(protected BusinessContext $context) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AuditLog::class);

        $user = $request->user();
        $companyIds = $user->companies()->pluck('companies.id')->all();

        if (empty($companyIds)) {
            return AuditLogResource::collect(collect());
        }

        $logs = AuditLog::query()
            ->whereIn('company_id', $companyIds)
            ->when($request->company_id, fn ($q, $id) => $q->where('company_id', $id))
            ->when($request->user_id, fn ($q, $id) => $q->where('user_id', $id))
            ->when($request->action, fn ($q, $action) => $q->where('action', $action))
            ->when($request->entity_type, fn ($q, $type) => $q->where('entity_type', $type))
            ->when($request->start_date, fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
            ->when($request->end_date, fn ($q, $date) => $q->whereDate('created_at', '<=', $date))
            ->with('user')
            ->latest()
            ->paginate($request->integer('per_page', 25));

        return $this->paginated(AuditLogResource::collection($logs));
    }
}
