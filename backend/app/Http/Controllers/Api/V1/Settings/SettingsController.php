<?php

namespace App\Http\Controllers\Api\V1\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Setting\UpdateSettingsRequest;
use App\Services\AuditService;
use App\Services\SettingsService;
use App\Support\BusinessContext;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private SettingsService $settings,
        protected AuditService $audit,
        protected BusinessContext $context
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->requirePermission('settings.view');

        $values = $this->settings->all($this->context->companyId());

        $grouped = collect($values)
            ->keys()
            ->map(fn ($key) => explode('.', $key)[0])
            ->unique()
            ->values();

        return $this->success([
            'values' => $values,
            'groups' => $grouped,
        ]);
    }

    public function show(Request $request, string $key): JsonResponse
    {
        $this->requirePermission('settings.view');

        return $this->success([
            'key' => $key,
            'value' => $this->settings->get($key, null, $this->context->companyId()),
        ]);
    }

    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        $this->requirePermission('settings.update');

        $companyId = $this->context->companyId();
        $values = $request->validated()['values'];

        $old = $this->settings->all($companyId);

        $this->settings->setMany($values, $companyId);

        $new = $this->settings->all($companyId);

        $changed = array_diff_assoc(
            array_map('serialize', $new),
            array_map('serialize', $old)
        );

        $this->audit->record('settings.update', 'setting', null, $old, array_intersect_key($new, $changed), $companyId);

        return $this->success(['values' => $new], 'Settings updated');
    }
}
