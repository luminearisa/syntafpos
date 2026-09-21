<?php

namespace App\Http\Controllers\Api\V1\Pos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\StorePosCartItemRequest;
use App\Http\Requests\Pos\UpdatePosCartItemRequest;
use App\Http\Requests\Pos\UpdatePosCartRequest;
use App\Http\Resources\PosCartItemResource;
use App\Http\Resources\PosCartResource;
use App\Models\PosCart;
use App\Models\PosCartItem;
use App\Services\PosCartService;
use App\Support\BusinessContext;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The working cart at the point of sale.
 *
 * There is no "create a cart" call from the client's point of view: opening the
 * till returns the cart this cashier is already working, or starts an empty one.
 * That keeps a reload, a network drop or a second tab from orphaning a scan.
 *
 * Hold and recall move a cart between active and held; deleting a held draft is
 * the only removal exposed. Nothing here touches stock, revenue or the ledger.
 */
class PosCartController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected PosCartService $carts,
        protected BusinessContext $context
    ) {}

    /**
     * The cashier's working cart, created empty on first touch.
     *
     * GET and POST answer the same way on purpose: opening the till is idempotent,
     * so a reload, a dropped request or a second tab resumes the same cart
     * instead of orphaning the scan already made.
     */
    public function current(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PosCart::class);

        return $this->success($this->present($this->carts->openWorkingCart($request->user())));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', PosCart::class);

        return $this->success($this->present($this->carts->openWorkingCart($request->user())), 'Cart ready', 201);
    }

    public function show(PosCart $cart): JsonResponse
    {
        $this->authorize('view', $cart);

        return $this->success($this->present($cart->fresh()));
    }

    /**
     * Attach a customer, set the cart discount or a note, clear the customer.
     */
    public function update(UpdatePosCartRequest $request, PosCart $cart): JsonResponse
    {
        $this->authorize('update', $cart);

        $cart = $this->carts->updateHeader($cart, $request->user(), $request->validated());

        return $this->success($this->present($cart), 'Cart updated');
    }

    /**
     * Scan or tap a product onto the cart.
     */
    public function addItem(StorePosCartItemRequest $request, PosCart $cart): JsonResponse
    {
        $this->authorize('update', $cart);

        $item = $this->carts->addItem($cart, $request->user(), $request->validated());

        return $this->success(
            [
                'item' => (new PosCartItemResource($item))->resolve(),
                'cart' => $this->present($cart->fresh()),
            ],
            'Added to cart',
            201
        );
    }

    public function updateItem(UpdatePosCartItemRequest $request, PosCart $cart, PosCartItem $item): JsonResponse
    {
        $this->authorize('update', $cart);
        $this->ensureLineBelongsToCart($cart, $item);

        $item = $this->carts->updateItem($cart, $item, $request->validated(), $request->user());

        return $this->success(
            [
                // Null when a quantity of zero dropped the line.
                'item' => $item === null ? null : (new PosCartItemResource($item))->resolve(),
                'cart' => $this->present($cart->fresh()),
            ],
            'Cart updated'
        );
    }

    public function removeItem(Request $request, PosCart $cart, PosCartItem $item): JsonResponse
    {
        $this->authorize('update', $cart);
        $this->ensureLineBelongsToCart($cart, $item);

        $this->carts->removeItem($cart, $item, $request->user());

        return $this->success($this->present($cart->fresh()), 'Item removed');
    }

    /**
     * Empty the working cart without deleting it, so the cashier stays put.
     */
    public function clear(Request $request, PosCart $cart): JsonResponse
    {
        $this->authorize('update', $cart);

        $cart = $this->carts->clearItems($cart, $request->user());

        return $this->success($this->present($cart), 'Cart cleared');
    }

    /**
     * Park the cart and hand back its recall code.
     */
    public function hold(Request $request, PosCart $cart): JsonResponse
    {
        $this->authorize('hold', $cart);

        $cart = $this->carts->hold($cart, $request->user());

        return $this->success($this->present($cart->fresh()), "Cart on hold. Recall code {$cart->number}.");
    }

    /**
     * Bring a parked cart back to this register.
     *
     * Addressed by its recall code rather than its id: the cashier reads a code
     * off the queue, they do not know database ids. The cart is resolved first
     * because the hold guard is per-cart — it checks the company the draft
     * actually belongs to, not whatever header the caller happens to send.
     */
    public function recall(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'number' => ['required', 'string', 'max:32'],
        ]);

        $cart = PosCart::query()
            ->visibleTo($request->user())
            ->where('company_id', $this->context->companyId())
            ->where('number', trim($validated['number']))
            ->first();

        if (! $cart) {
            return $this->error("No held cart matches code {$validated['number']}.", 404);
        }

        $this->authorize('hold', $cart);

        $cart = $this->carts->recall($cart, $request->user());

        return $this->success($this->present($cart), 'Cart recalled');
    }

    /**
     * The held-cart queue, oldest first, so the cashier can pick from a list
     * instead of typing a code.
     */
    public function held(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PosCart::class);

        $carts = PosCart::query()
            ->visibleTo($request->user())
            ->where('company_id', $this->context->companyId())
            ->where('status', 'held')
            ->with(['customer:id,name,phone,customer_code', 'items:id,pos_cart_id,quantity,line_total', 'cashier:id,name'])
            ->orderBy('held_at')
            ->get();

        return $this->success($carts->map(fn (PosCart $cart) => [
            'id' => $cart->id,
            'number' => $cart->number,
            'label' => $cart->label,
            'status' => $cart->status->value,
            'held_at' => $cart->held_at?->toIso8601String(),
            'item_count' => $cart->items->count(),
            'total_quantity' => $cart->items->reduce(fn (string $c, $i) => bcadd($c, (string) $i->quantity, 6), '0'),
            'grand_total' => (string) $cart->grand_total,
            'currency' => $cart->currency,
            'customer' => $cart->customer ? [
                'id' => $cart->customer->id,
                'name' => $cart->customer->name,
                'phone' => $cart->customer->phone,
                'customer_code' => $cart->customer->customer_code,
            ] : null,
            'cashier' => $cart->cashier ? ['id' => $cart->cashier->id, 'name' => $cart->cashier->name] : null,
        ])->all());
    }

    /**
     * Discard a parked draft for good.
     *
     * Only a held cart may be deleted through here: an active cart is cleared
     * with DELETE .../items instead, which leaves the working cart in place.
     */
    public function destroy(Request $request, PosCart $cart): JsonResponse
    {
        $this->authorize('delete', $cart);

        $this->carts->deleteHeld($cart, $request->user());

        return $this->success(null, 'Held cart deleted');
    }

    private function present(PosCart $cart): array
    {
        return (new PosCartResource($cart->load([
            'items.product:id,name,sku,barcode,is_sellable,is_active',
            'items.productVariant',
            // The selector shows which tier a customer is billed at, so the
            // list travels with them rather than as an id to look up.
            'customer:id,name,phone,customer_code,price_list_id,customer_group_id,credit_limit',
            'customer.priceList:id,name,status',
            'register:id,code,name',
            'cashier:id,name',
        ])))->resolve();
    }

    /**
     * Route binding can pair a cart with a line from another cart; the service
     * checks permissions and ownership, this checks the pair itself.
     */
    private function ensureLineBelongsToCart(PosCart $cart, PosCartItem $item): void
    {
        abort_unless($item->pos_cart_id === $cart->id, 404, 'That line is not on this cart.');
    }
}
