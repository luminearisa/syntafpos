<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Support\BusinessContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active business context (company/branch/warehouse/register)
 * from request headers and binds it for the request lifetime.
 *
 * Scope selection is advisory: controllers still enforce access through
 * policies, so a forged header grants nothing the user does not already have.
 */
class ResolveBusinessContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $context = App::make(BusinessContext::class);

        if ($request->user()) {
            if ($id = $this->id($request->header('X-Company-Id'))) {
                $company = $request->user()
                    ->companies()
                    ->where('companies.id', $id)
                    ->first();

                $context->setCompany($company ?? Company::find($id));

                $this->applyChildContext($context, $request);
            }
        }

        return $next($request);
    }

    private function applyChildContext(BusinessContext $context, Request $request): void
    {
        $company = $context->company();

        if (! $company) {
            return;
        }

        $user = $request->user();

        if ($id = $this->id($request->header('X-Branch-Id'))) {
            $context->setBranch(
                $user->branches()
                    ->where('branches.company_id', $company->id)
                    ->where('branches.id', $id)
                    ->first()
            );
        }

        if ($id = $this->id($request->header('X-Warehouse-Id'))) {
            $context->setWarehouse(
                $user->warehouses()
                    ->where('warehouses.company_id', $company->id)
                    ->where('warehouses.id', $id)
                    ->first()
            );
        }

        if ($id = $this->id($request->header('X-Register-Id'))) {
            $context->setRegister(
                $user->registers()
                    ->where('registers.company_id', $company->id)
                    ->where('registers.id', $id)
                    ->first()
            );
        }
    }

    private function id(mixed $value): ?int
    {
        if (! is_string($value) || ! ctype_digit($value)) {
            return null;
        }

        return (int) $value;
    }
}
