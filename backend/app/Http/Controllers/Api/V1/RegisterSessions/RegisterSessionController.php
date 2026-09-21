<?php

namespace App\Http\Controllers\Api\V1\RegisterSessions;

use App\Enums\CashMovementType;
use App\Enums\EntityStatus;
use App\Enums\RegisterSessionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterSessions\ApproveVarianceRequest;
use App\Http\Requests\RegisterSessions\CashMovementRequest;
use App\Http\Requests\RegisterSessions\CloseRegisterRequest;
use App\Http\Requests\RegisterSessions\OpenRegisterRequest;
use App\Http\Resources\CashMovementResource;
use App\Http\Resources\RegisterSessionResource;
use App\Models\CashMovement;
use App\Models\Register;
use App\Models\RegisterSession;
use App\Services\RegisterSessionService;
use App\Support\BusinessContext;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The register session endpoints: open a drawer, move cash through it, close it
 * against a count, and sign off the difference.
 *
 * The routes follow the shift's own lifecycle rather than a resource CRUD, and for
 * the same reason sales do: a counted shift is not a row someone edits. There is no
 * PUT and no DELETE anywhere here — the correction a shift accepts is a reopen and a
 * second count, which keeps the first count on the record. Everything that decides a
 * figure (`expected`, `variance`, whether approval is required) is computed by
 * `RegisterSessionService`; these actions only hand it the numbers a human is
 * allowed to supply.
 *
 * Tenancy is the policy's job. Every action below authorises against
 * `RegisterSessionPolicy` before touching the service, and the queries the list and
 * `current` endpoints run are `visibleTo`-scoped, so a shift on another company's
 * register is neither listed nor found here.
 */
class RegisterSessionController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected RegisterSessionService $shifts,
        protected BusinessContext $context,
    ) {}

    /**
     * Shift history, newest first.
     *
     * Deliberately without the computed summary: the list shows what a shift was
     * counted at, which is stored on the row. Recomputing expected cash for every
     * row would be a handful of aggregates per shift, and a variance a manager is
     * actually interrogating always belongs to one shift — that is what /show is for.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', RegisterSession::class);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(RegisterSessionStatus::class)],
            'register_id' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'integer'],
            'cashier_id' => ['nullable', 'integer'],
            'awaiting_approval' => ['nullable', 'boolean'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $sessions = RegisterSession::query()
            ->visibleTo($request->user())
            ->when(
                $this->context->companyId(),
                fn ($q) => $q->where('company_id', $this->context->companyId())
            )
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['register_id'] ?? null, fn ($q, $id) => $q->where('register_id', $id))
            ->when($filters['branch_id'] ?? null, fn ($q, $id) => $q->where('branch_id', $id))
            ->when($filters['cashier_id'] ?? null, fn ($q, $id) => $q->where('cashier_id', $id))
            ->when($request->boolean('awaiting_approval'), fn ($q) => $q
                ->where('status', RegisterSessionStatus::Closed->value)
                ->where('requires_approval', true)
                ->where('is_approved', false))
            ->when($filters['date_from'] ?? null, fn ($q, $from) => $q->whereDate('opened_at', '>=', $from))
            ->when($filters['date_to'] ?? null, fn ($q, $to) => $q->whereDate('opened_at', '<=', $to))
            ->with([
                'register:id,code,name',
                'cashier:id,name',
                'approvedBy:id,name',
            ])
            ->latest('opened_at')
            ->latest('id')
            ->paginate((int) ($filters['per_page'] ?? 20));

        return $this->paginated(RegisterSessionResource::collection($sessions));
    }

    /**
     * One shift, with its movements and its current figures.
     */
    public function show(Request $request, RegisterSession $registerSession): JsonResponse
    {
        $this->authorize('view', $registerSession);

        return $this->success($this->present($registerSession));
    }

    /**
     * The shift a register is working right now, or null.
     *
     * `null` is a success rather than a 404 because it is the normal answer for a
     * till that has not opened yet — a till page that 404s on boot cannot tell
     * "nothing open" apart from "not allowed", and the two need different screens.
     */
    public function current(Request $request): JsonResponse
    {
        $this->authorize('viewAny', RegisterSession::class);

        $filters = $request->validate([
            'register_id' => ['nullable', 'integer', 'exists:registers,id'],
        ]);

        $registerId = $filters['register_id'] ?? $this->context->registerId();
        $companyId = $this->context->companyId();

        $session = $this->shifts->currentFor($registerId === null ? null : (int) $registerId, $companyId);

        if (! $session) {
            return $this->success(null, 'No register is open on this till.');
        }

        // A register the user cannot see is not disclosed as open. The scope check
        // happens here rather than by returning the shift and letting the client
        // decide, because this endpoint takes a register id from the query string.
        $this->authorize('view', $session);

        return $this->success($this->present($session));
    }

    /**
     * Open a register with float.
     */
    public function open(OpenRegisterRequest $request): JsonResponse
    {
        $this->authorize('open', RegisterSession::class);

        $session = $this->shifts->open($request->user(), $request->validated());

        return $this->success(
            $this->present($session),
            "Register {$session->register?->code} opened as {$session->number}.",
            201
        );
    }

    /**
     * Close and count a shift.
     */
    public function close(CloseRegisterRequest $request, RegisterSession $registerSession): JsonResponse
    {
        $this->authorize('close', $registerSession);

        $session = $this->shifts->close($registerSession, $request->user(), $request->validated());

        $message = $session->requires_approval
            ? sprintf('Shift %s closed with a variance needing approval.', $session->number)
            : sprintf('Shift %s closed.', $session->number);

        return $this->success($this->present($session), $message);
    }

    /**
     * Accept a variance over the shop's tolerance.
     */
    public function approve(ApproveVarianceRequest $request, RegisterSession $registerSession): JsonResponse
    {
        $this->authorize('approve', $registerSession);

        $session = $this->shifts->approve($registerSession, $request->user(), $request->validated());

        return $this->success($this->present($session), "Variance on {$session->number} approved.");
    }

    /**
     * Put a closed shift back open, with a reason.
     */
    public function reopen(Request $request, RegisterSession $registerSession): JsonResponse
    {
        $this->authorize('reopen', $registerSession);

        $input = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $session = $this->shifts->reopen($registerSession, $request->user(), $input);

        return $this->success($this->present($session), "Shift {$session->number} reopened.");
    }

    /**
     * The cash movements on a shift, newest first.
     */
    public function movements(Request $request, RegisterSession $registerSession): JsonResponse
    {
        $this->authorize('view', $registerSession);

        $filters = $request->validate([
            'type' => ['nullable', Rule::enum(CashMovementType::class)],
            'group' => ['nullable', Rule::in(['in', 'out', 'refund'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $movements = CashMovement::query()
            ->where('register_session_id', $registerSession->id)
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($filters['group'] ?? null, function ($q, $group) {
                $types = array_map(
                    fn (CashMovementType $case) => $case->value,
                    array_filter(CashMovementType::cases(), fn (CashMovementType $case) => $case->group() === $group)
                );
                $q->whereIn('type', $types === [] ? [''] : array_values($types));
            })
            ->with(['user:id,name', 'session:id,number'])
            ->latest('occurred_at')
            ->latest('id')
            ->paginate((int) ($filters['per_page'] ?? 50));

        return $this->paginated(CashMovementResource::collection($movements));
    }

    /**
     * Record cash in or cash out on an open shift.
     */
    public function storeMovement(CashMovementRequest $request, RegisterSession $registerSession): JsonResponse
    {
        $this->authorize('process', $registerSession);

        $movement = $this->shifts->recordMovement($registerSession, $request->user(), $request->validated());

        return $this->success(
            new CashMovementResource($movement),
            sprintf('%s of %s recorded.', $movement->type->label(), $movement->amount),
            201
        );
    }

    /**
     * The closing report, as lines rather than as a table the client assembles.
     *
     * The eight figures the spec names, in the order they are read at a counter,
     * each with the value already computed by the one summarising method the close
     * itself used. A manager comparing this to what the till displayed is reading the
     * same arithmetic twice, not two implementations of it.
     */
    public function report(RegisterSession $registerSession): JsonResponse
    {
        $this->authorize('view', $registerSession);

        $summary = $this->shifts->summarise($registerSession);
        $currency = (string) ($registerSession->company?->currency ?? 'IDR');

        return $this->success([
            'shift' => $this->present($registerSession),
            'currency' => $currency,
            'report' => [
                ['key' => 'opening_balance', 'label' => 'Opening cash', 'value' => $summary['opening_balance']],
                ['key' => 'cash_sales', 'label' => 'Cash sales', 'value' => $summary['cash_sales']],
                ['key' => 'cash_refunds', 'label' => 'Cash refunds', 'value' => $summary['cash_refunds']],
                ['key' => 'cash_in', 'label' => 'Cash in', 'value' => $summary['cash_in']],
                ['key' => 'cash_out', 'label' => 'Cash out', 'value' => $summary['cash_out']],
                ['key' => 'expected_cash', 'label' => 'Expected cash', 'value' => $summary['expected_cash'], 'emphasis' => true],
                ['key' => 'actual_balance', 'label' => 'Actual cash', 'value' => $summary['actual_balance']],
                ['key' => 'variance', 'label' => 'Variance', 'value' => $summary['variance'], 'emphasis' => true],
                // Not part of the eight: the shift's own sum cannot change, so cash
                // taken on this register outside any shift is shown here as the
                // reason a count may read long.
                ['key' => 'unattributed_cash', 'label' => 'Cash outside any shift', 'value' => $summary['unattributed_cash']],
            ],
        ]);
    }

    /**
     * The registers this shop has, so a client can name the drawer to open without
     * guessing ids.
     *
     * Open shift attached: the till decides whether to show "Open register" or the
     * current shift's figures from one round trip, and those two answers have to
     * agree with each other.
     */
    public function registers(Request $request): JsonResponse
    {
        $this->authorize('viewAny', RegisterSession::class);

        $registers = Register::query()
            ->visibleTo($request->user())
            ->when(
                $this->context->companyId(),
                fn ($q) => $q->where('company_id', $this->context->companyId())
            )
            ->when(
                $this->context->branchId(),
                fn ($q) => $q->where('branch_id', $this->context->branchId())
            )
            ->where('status', EntityStatus::Active->value)
            ->with('branch:id,code,name')
            ->orderBy('code')
            ->get();

        return $this->success($registers->map(fn (Register $register) => [
            'id' => $register->id,
            'code' => $register->code,
            'name' => $register->name,
            'branch_id' => $register->branch_id,
            'branch_name' => $register->branch?->name,
            'open_session' => ($session = $this->shifts->currentFor($register->id, $register->company_id))
                ? [
                    'id' => $session->id,
                    'number' => $session->number,
                    'cashier' => $session->cashier?->name,
                    'opened_at' => $session->opened_at?->toIso8601String(),
                ]
                : null,
        ])->all());
    }

    /**
     * A shift with its movements, its people, and the live figures.
     */
    private function present(RegisterSession $session): RegisterSessionResource
    {
        $session->load([
            'register:id,code,name',
            'branch:id,code,name',
            'cashier:id,name',
            'openedBy:id,name',
            'closedBy:id,name',
            'approvedBy:id,name',
            'reopenedBy:id,name',
            // The whole movement, not a column subset: the resource reads its type,
            // amount and reason, and a partial select would report them as null.
            'movements.user:id,name',
        ]);

        return (new RegisterSessionResource($session))->withSummary($this->shifts->summarise($session));
    }
}
