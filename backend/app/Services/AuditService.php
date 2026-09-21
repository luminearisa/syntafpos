<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Support\BusinessContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuditService
{
    public function __construct(private BusinessContext $context) {}

    /**
     * Record an audit entry for the current request.
     *
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function record(
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?int $companyId = null,
        ?int $userId = null
    ): AuditLog {
        /** @var Request $request */
        $request = request();

        return AuditLog::create([
            'company_id' => $companyId ?? $this->context->companyId(),
            // Explicit actor wins; auth events fire before the session user resolves.
            'user_id' => $userId ?? Auth::id(),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $request?->ip(),
            'user_agent' => $this->truncateUserAgent($request?->userAgent()),
        ]);
    }

    private function truncateUserAgent(?string $userAgent): ?string
    {
        if ($userAgent === null) {
            return null;
        }

        return strlen($userAgent) > 500 ? substr($userAgent, 0, 500) : $userAgent;
    }
}
