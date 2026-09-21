<?php

namespace App\Policies;

use App\Models\Branch;

class BranchPolicy extends BusinessEntityPolicy
{
    protected function module(): string
    {
        return 'branches';
    }

    protected function companyIdOf(mixed $model): ?int
    {
        return $model instanceof Branch ? $model->company_id : null;
    }
}
