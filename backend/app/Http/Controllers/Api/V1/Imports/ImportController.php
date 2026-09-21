<?php

namespace App\Http\Controllers\Api\V1\Imports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Import\StoreImportRequest;
use App\Services\AuditService;
use App\Services\Import\ImportContext;
use App\Services\Import\ImportEngine;
use App\Services\Import\ImportRegistry;
use App\Support\ApiResponse;
use App\Support\BusinessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bulk import, in two phases (§30).
 *
 * Preview parses and validates a file and reports every row's outcome without
 * writing anything. Commit re-reads the same file, re-validates it on the
 * server, and only then writes — a client's copy of a preview is never trusted
 * (§44). Both phases answer with the same error map, so a user fixing a file
 * after a preview and a user fixing it after a rejected commit see the same
 * thing.
 */
class ImportController extends Controller
{
    use ApiResponse;
    use AuthorizesRequests;

    public function __construct(
        protected ImportRegistry $registry,
        protected ImportEngine $engine,
        protected AuditService $audit,
        protected BusinessContext $context
    ) {}

    public function preview(StoreImportRequest $request): JsonResponse
    {
        $importer = $this->registry->importer($request->string('entity')->toString());
        $importContext = $this->context($request);

        $this->authorize('permission', [$importer->permission(), $importContext->companyId]);

        $result = $this->engine->analyze($importer->entity(), $request->file('file'), $importContext);

        // A file that cannot be parsed at all has no rows to report, so it is
        // raised as a validation failure: the same file gets the same answer
        // from preview and from commit.
        if ($result->fileErrors !== []) {
            throw ValidationException::withMessages($result->fileErrors);
        }

        return $this->success([
            'entity' => $importer->entity(),
            'summary' => $result->summary(),
            'errors' => $result->errorMap(),
            'file_errors' => $result->fileErrors,
            'rows' => $result->rows,
        ], $result->hasErrors()
            ? 'Preview completed with validation errors'
            : 'Preview completed; no errors were found');
    }

    public function commit(StoreImportRequest $request): JsonResponse
    {
        $importer = $this->registry->importer($request->string('entity')->toString());
        $importContext = $this->context($request);

        $this->authorize('permission', [$importer->permission(), $importContext->companyId]);

        // §44: validation sits inside the transaction, so a row that fails
        // after earlier rows were written rolls them back too. A partial
        // import is not an outcome this endpoint can produce.
        $written = DB::transaction(function () use ($importer, $request, $importContext): array {
            $written = $this->engine->commit($importer->entity(), $request->file('file'), $importContext);

            $this->audit->record(
                'import.commit',
                $importer->entity(),
                null,
                null,
                ['entity' => $importer->entity(), 'rows' => count($written)],
                $importContext->companyId,
                $importContext->userId
            );

            return $written;
        });

        return $this->success([
            'entity' => $importer->entity(),
            'imported' => count($written),
        ], 'Import complete: '.count($written).' row(s) imported', 201);
    }

    /**
     * The company the import runs in: the request's explicit choice when one is
     * given, otherwise the context the request resolved.
     */
    private function context(StoreImportRequest $request): ImportContext
    {
        $companyId = $request->integer('company_id') ?: $this->context->companyId();

        if (! $companyId) {
            throw new AuthorizationException('No active company context.');
        }

        $user = $request->user();

        if (! $user->companies()->where('companies.id', $companyId)->exists()) {
            throw new AuthorizationException('You do not have access to this company.');
        }

        return new ImportContext($companyId, $user->id);
    }
}
