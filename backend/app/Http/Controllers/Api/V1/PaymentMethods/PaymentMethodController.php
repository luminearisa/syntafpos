<?php

namespace App\Http\Controllers\Api\V1\PaymentMethods;

use App\Contracts\Payments\PaymentProviderRegistry;
use App\Enums\PaymentChannel;
use App\Http\Controllers\Controller;
use App\Http\Requests\PaymentMethods\StorePaymentMethodRequest;
use App\Http\Requests\PaymentMethods\UpdatePaymentMethodRequest;
use App\Http\Resources\PaymentMethodResource;
use App\Models\PaymentMethod;
use App\Services\AuditService;
use App\Support\BusinessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The shop's own list of ways to take money.
 *
 * Ordinary master data, with the two things master data always needs when money
 * has already flowed over it: a channel a method has used cannot be retargeted,
 * and a method that has taken payment cannot be deleted — a shop deactivates it,
 * so the payment rows keep pointing at something with a name and a history.
 *
 * `is_default` is one-per-company by construction rather than by validation: two
 * defaults would leave a till guessing which button to open on, and that is the
 * kind of ambiguity a cashier should never be asked to resolve.
 */
class PaymentMethodController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected AuditService $audit,
        protected BusinessContext $context,
        protected PaymentProviderRegistry $providers,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PaymentMethod::class);

        $methods = PaymentMethod::query()
            ->visibleTo($request->user())
            ->when(
                $this->context->companyId(),
                fn ($q) => $q->where('company_id', $this->context->companyId())
            )
            ->when($request->boolean('active_only'), fn ($q) => $q->where('is_active', true))
            ->when($request->search, function ($q, $search) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], trim((string) $search)).'%';
                $q->where(fn ($inner) => $inner->where('name', 'like', $like)->orWhere('code', 'like', $like));
            })
            ->when(
                PaymentChannel::tryFrom((string) $request->channel),
                fn ($q, PaymentChannel $channel) => $q->where('channel', $channel->value)
            )
            ->withCount('payments')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate((int) $request->integer('per_page', 50));

        return $this->paginated(PaymentMethodResource::collection($methods));
    }

    /**
     * The methods a cashier may pick, in till order.
     *
     * Separate from the admin list because the answer is different in one case that
     * matters: a shop that has configured nothing gets the defaults built from the
     * catalogue, so a till can take cash on a company whose owner has never opened
     * this screen. A blank list here stops a shop selling, which is not what a
     * missing setting is for.
     */
    public function available(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PaymentMethod::class);

        $companyId = $this->context->companyId();

        if ($companyId !== null) {
            $configured = PaymentMethod::query()
                ->where('company_id', $companyId)
                ->available()
                ->get()
                ->map(fn (PaymentMethod $method) => (new PaymentMethodResource($method))->resolve($request));

            if ($configured->isNotEmpty()) {
                return $this->success($configured);
            }
        }

        // Nothing selected and nothing configured: the till still needs buttons.
        // These rows have no id, which is how PaymentService is told to resolve a
        // tender by channel instead of by a configuration the shop never made.
        return $this->success(collect(PaymentChannel::defaultChannels())
            ->map(fn (PaymentChannel $channel) => [
                'id' => null,
                'company_id' => $companyId,
                'code' => strtoupper($channel->value),
                'name' => $channel->label(),
                'channel' => $channel->value,
                'channel_label' => $channel->label(),
                'provider' => null,
                'icon' => null,
                'description' => null,
                'requires_reference' => false,
                'is_default' => $channel === PaymentChannel::Cash,
                'is_active' => true,
                'sort_order' => 0,
                'settings' => null,
                'takes_tender' => $channel->takesTender(),
                'uses_customer_account' => $channel->usesCustomerAccount(),
            ])
            ->all());
    }

    public function store(StorePaymentMethodRequest $request): JsonResponse
    {
        $this->authorize('create', PaymentMethod::class);
        $this->ensureCompanyAccess($request->targetCompanyId());

        $data = $request->validated();
        $data['company_id'] = $request->targetCompanyId();
        $this->guardProvider($data);

        $method = PaymentMethod::create($data);
        $this->uniqueDefault($method);

        $this->audit->record('payment_method.create', 'payment_method', $method->id, null, $data, $method->company_id);

        // Read back rather than answering from the instance just inserted: columns
        // the form did not send — `is_active` above all — carry their database
        // default, and a till told "inactive" about a method it can already see
        // would be a list that contradicts itself.
        return $this->success(new PaymentMethodResource($method->fresh()), 'Payment method created', 201);
    }

    public function show(PaymentMethod $paymentMethod): JsonResponse
    {
        $this->authorize('view', $paymentMethod);

        $paymentMethod->loadCount('payments');

        return $this->success(new PaymentMethodResource($paymentMethod));
    }

    public function update(UpdatePaymentMethodRequest $request, PaymentMethod $paymentMethod): JsonResponse
    {
        $this->authorize('update', $paymentMethod);

        $data = $request->validated();

        if (isset($data['channel']) && $data['channel'] !== $paymentMethod->channel->value && $paymentMethod->payments()->exists()) {
            throw ValidationException::withMessages([
                'channel' => sprintf(
                    'Cannot change %s to another channel: payments have already been taken on it. Deactivate it and configure a new method instead.',
                    $paymentMethod->name
                ),
            ]);
        }

        // The channel a method will end up on decides the guard, not the one it
        // arrived with: correcting an unused method's channel while also naming a
        // provider must be checked against the channel being moved to.
        $prospective = $data + ['channel' => $paymentMethod->channel->value];
        $this->guardProvider($prospective, $paymentMethod);

        $old = $paymentMethod->only(array_keys($data));
        $paymentMethod->update($data);
        $this->uniqueDefault($paymentMethod->fresh());

        $this->audit->record(
            'payment_method.update',
            'payment_method',
            $paymentMethod->id,
            $old,
            $paymentMethod->fresh()->only(array_keys($old)),
            $paymentMethod->company_id
        );

        return $this->success(new PaymentMethodResource($paymentMethod->fresh()));
    }

    /**
     * Retire a method — only while nothing has been paid on it.
     *
     * A method with payment history is deactivated instead. Deleting it would not
     * destroy the payments (the foreign key nulls out and the row keeps its own
     * name and channel), but it would erase the configuration a shop's finance
     * person needs in order to read those payments as anything other than strings.
     */
    public function destroy(PaymentMethod $paymentMethod): JsonResponse
    {
        $this->authorize('delete', $paymentMethod);

        $used = $paymentMethod->payments()->count();

        if ($used > 0) {
            throw ValidationException::withMessages([
                'payment_method' => sprintf(
                    '%s has %s payment%s against it. Deactivate it instead of deleting it, so those payments keep pointing at a method.',
                    $paymentMethod->name,
                    $used,
                    $used === 1 ? '' : 's'
                ),
            ]);
        }

        $before = $paymentMethod->only(['name', 'code', 'channel', 'is_active', 'is_default']);

        $paymentMethod->delete();

        $this->audit->record('payment_method.delete', 'payment_method', $paymentMethod->id, $before, null, $paymentMethod->company_id);

        return $this->success(null, 'Payment method deleted');
    }

    /**
     * Keep exactly one default per company.
     *
     * Set rather than refused: a shop toggling the default onto a second method has
     * said which one it means, and making it un-tick the first to satisfy the
     * server is a form that fights its own user.
     */
    private function uniqueDefault(PaymentMethod $method): void
    {
        if (! $method->is_default) {
            return;
        }

        PaymentMethod::query()
            ->where('company_id', $method->company_id)
            ->whereKeyNot($method->id)
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }

    /**
     * Refuse a provider the installation cannot reach, or one that cannot speak
     * this method's channel.
     *
     * Checked here as well as at the counter: a method configured against a
     * provider nobody has installed will refuse every tender it is given, and an
     * admin should learn that while setting it up rather than from a queue of
     * declined payments. A key naming nothing installed is only a mistake when the
     * shop is on a box without the gateway — so it is a warning-shaped refusal,
     * with the keys that *are* available in the message.
     *
     * @param  array<string, mixed>  $data
     */
    private function guardProvider(array $data, ?PaymentMethod $existing = null): void
    {
        $key = $data['provider'] ?? $existing?->provider;

        if (blank($key)) {
            return;
        }

        if (! $this->providers->has($key)) {
            $available = $this->providers->keys();

            throw ValidationException::withMessages([
                'provider' => sprintf(
                    'No payment provider %s is installed on this server%s. Leave the provider empty to record tenders on this method directly.',
                    $key,
                    $available === [] ? '' : '. Available: '.implode(', ', $available)
                ),
            ]);
        }

        $channel = PaymentChannel::from($data['channel'] ?? $existing?->channel->value ?? PaymentChannel::Cash->value);

        if (! $this->providers->get($key)->supports($channel)) {
            throw ValidationException::withMessages([
                'provider' => sprintf(
                    '%s cannot take %s payments.',
                    $key,
                    strtolower($channel->label())
                ),
            ]);
        }
    }

    private function ensureCompanyAccess(int $companyId): void
    {
        $user = request()->user();

        if (! $user->companies()->where('companies.id', $companyId)->exists()) {
            throw new AuthorizationException('You do not have access to this company.');
        }
    }
}
