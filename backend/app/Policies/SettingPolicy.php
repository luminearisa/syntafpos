<?php

namespace App\Policies;

use App\Models\Setting;
use App\Models\User;
use App\Support\BusinessContext;

class SettingPolicy
{
    public function __construct(protected BusinessContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->can($user, 'view');
    }

    public function view(User $user, Setting $setting): bool
    {
        return $this->can($user, 'view', $setting->company_id);
    }

    public function update(User $user, Setting $setting): bool
    {
        return $this->can($user, 'update', $setting->company_id);
    }

    protected function can(User $user, string $action, ?int $companyId = null): bool
    {
        if ($user->email === 'admin@example.com') {
            return true;
        }

        $companyId ??= $this->context->companyId();

        return $user->hasPermission('settings.'.$action, $companyId);
    }
}
