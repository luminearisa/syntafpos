<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Services\AuditService;
use App\Support\BusinessContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;

class ForgotPasswordController extends Controller
{
    public function __construct(
        protected AuditService $audit,
        protected BusinessContext $context
    ) {}

    public function sendResetLinkEmail(ForgotPasswordRequest $request): JsonResponse
    {
        $status = Password::sendResetLink($request->only('email'));
        $this->audit->record('password.forgot', 'user', null, null, [
            'email' => $request->email,
            'status' => (string) $status,
        ]);
        $sent = $status === Password::RESET_LINK_SENT;

        return $this->success(
            null,
            __($status),
            $sent ? 200 : 400
        );
    }
}
