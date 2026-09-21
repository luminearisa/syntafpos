<?php

namespace App\Http\Controllers\Api\V1\Exports;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use App\Services\Export\ExportRegistry;
use App\Support\BusinessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CSV export of the entities the reports cover (§31).
 *
 * The entity decides what the file contains and which permission reads it; the
 * driver decides how the rows are serialised. Streaming keeps the response
 * bounded in memory regardless of how many rows the query finds.
 */
class ExportController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected ExportRegistry $registry,
        protected AuditService $audit,
        protected BusinessContext $context
    ) {}

    public function export(Request $request, string $entity): Response
    {
        $exporter = $this->registry->exporter($entity);
        $companyId = $this->resolveCompany($request);

        $this->authorize('permission', [$exporter->permission(), $companyId]);

        $driver = $this->registry->driver($request->string('format')->toString());

        // Counted before the stream opens: the audit record states how many
        // rows left the database, which is what makes an export verifiable.
        $total = $exporter->query($request, $companyId)->count();

        $this->audit->record(
            'export.'.$driver->format(),
            $exporter->entity(),
            null,
            null,
            ['entity' => $exporter->entity(), 'rows' => $total, 'filters' => $request->only($exporter->filters())],
            $companyId,
            $request->user()->id
        );

        return $driver->response($exporter, $request, $companyId);
    }

    /**
     * The company whose rows the export may contain: an explicit request
     * choice, or the context the request resolved, and only ever one the user
     * belongs to.
     */
    private function resolveCompany(Request $request): int
    {
        $companyId = $request->integer('company_id') ?: $this->context->companyId();

        if (! $companyId) {
            throw new AuthorizationException('No active company context.');
        }

        if (! $request->user()->companies()->where('companies.id', $companyId)->exists()) {
            throw new AuthorizationException('You do not have access to this company.');
        }

        return $companyId;
    }
}
