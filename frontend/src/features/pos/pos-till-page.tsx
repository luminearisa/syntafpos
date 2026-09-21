import { type RefObject, useState } from 'react';
import { posApi } from '@/api/services';
import type { Customer, PosCartItem, PosProduct } from '@/types';
import { useAuthStore } from '@/stores/auth-store';
import { apiErrorMessage } from '@/utils/api-error';
import { formatMoneyString } from '@/utils/format';
import { useToast } from '@/components/ui/toast';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { ErrorState, LoadingState } from '@/components/ui/state';
import { CheckoutDialog } from '@/features/sales/checkout-dialog';
import { ShiftBar } from '@/features/registers/shift-bar';
import { useCurrentShift } from '@/features/registers/use-shift';
import { CartPanel } from './cart-panel';
import { ProductGrid } from './product-grid';
import { CustomerFooterButton, CustomerPickerModal } from './customer-picker-modal';
import { DiscountFooterButton, DiscountModal, type CartHeaderPatch } from './discount-modal';
import { HoldDialog, RecallDialog } from './hold-recall';
import { usePosCart } from './use-pos-cart';
import { useAutoFocus, usePosShortcuts } from './use-pos-shortcuts';

type Dialog = 'customer' | 'discount' | 'hold' | 'recall' | 'checkout' | null;

/**
 * The till (Phase 3.1, checkout in 3.2).
 *
 * Search box across the top, products on the left, the cart on the right, and a
 * footer that reads Customer | Discount | Tax | TOTAL | Checkout — the order a
 * sale is actually completed in, and the order a cashier's eyes move down at the
 * moment of taking money.
 *
 * Nothing on this screen decides a price or a total. The server resolves prices
 * through the price engine for the customer on the cart, and every mutation
 * answers with the recomputed cart, which is what the footer and the cart panel
 * render. Checkout is the one place that leaves the till: it hands the cart id to
 * POST /sales, and the sale, its invoice number, its payments and its stock out
 * either all exist or none do.
 */
export default function PosTillPage() {
  const can = useAuthStore((state) => state.can);
  const registerId = useAuthStore((state) => state.scope.registerId);
  const { toast } = useToast();

  const mayWork = can('pos.transact');
  const mayHold = can('pos.hold');

  const till = usePosCart(can('pos.view'));
  const { cart } = till;

  // Phase 3.4: the drawer this till's cash belongs to. Read alongside the cart so
  // the bar and the totals on screen are the server's own two answers.
  const maySeeShifts = can('register_sessions.view');
  const shift = useCurrentShift(maySeeShifts);

  const [term, setTerm] = useState('');
  const [dialog, setDialog] = useState<Dialog>(null);
  // A counter rather than a boolean: pressing F2 twice must re-focus and
  // re-select, which a signal that never changes back would not do.
  const [focusTick, setFocusTick] = useState(0);
  const searchRef = useAutoFocus<HTMLInputElement>(focusTick);

  /** A scan is the search box's Enter key: the code resolves to exactly one line. */
  const scan = async () => {
    const code = term.trim();

    if (!code || !cart || !mayWork) {
      return;
    }

    try {
      const { data } = await posApi.scan(code);

      // The scan answer carries the payload the cart endpoint wants, so the two
      // never disagree about what a code means.
      await till.addLine.mutateAsync(data.add_to_cart);
      setTerm('');
      setFocusTick((value) => value + 1);
    } catch (error) {
      // Not a code, most likely: leave the text where the cashier typed it and
      // let the grid answer as a search instead of an exact lookup.
      toast({
        variant: 'warning',
        title: 'Not scanned',
        message: apiErrorMessage(error, 'No product matches that code. Showing it as a search.'),
      });
    }
  };

  const addFromGrid = (product: PosProduct) => {
    till.addLine.mutate({ product_id: product.product_id, quantity: '1' });
  };

  const setQuantity = (item: PosCartItem, quantity: string) => {
    till.patchLine.mutate({ itemId: item.id, data: { quantity } });
  };

  const setLineDiscount = (item: PosCartItem, discount: string, type: 'amount' | 'percent') => {
    till.patchLine.mutate({ itemId: item.id, data: { discount, discount_type: type } });
  };

  const setLineNotes = (item: PosCartItem, notes: string) => {
    till.patchLine.mutate({ itemId: item.id, data: { notes } });
  };

  const pickCustomer = (customer: Customer | null) => {
    till.patchCart.mutate(
      customer ? { customer_id: customer.id } : { clear_customer: true }
    );
  };

  const applyHeader = (patch: CartHeaderPatch) => {
    till.patchCart.mutate({ ...patch });
  };

  const hold = (label: string | null) => {
    till.hold.mutate(label);
  };

  const recall = (number: string) => {
    till.recall.mutate(number);
  };

  const closeDialog = () => setDialog(null);

  // While a dialog is open it owns the keyboard, and Modal closes itself on Esc,
  // so the till's own bindings step back rather than stacking dialogs.
  usePosShortcuts(
    {
      F2: () => setFocusTick((value) => value + 1),
      F4: () => mayWork && setDialog('customer'),
      F6: () => mayWork && setDialog('discount'),
      F8: () => mayHold && setDialog('hold'),
      F9: () => mayHold && setDialog('recall'),
      // Defined below the hook call, hence the arrow wrapper.
      F10: () => checkout(),
      Escape: () => setTerm(''),
    },
    dialog === null
  );

  /** Opening the payment dialog is all the till does; CheckoutDialog posts the sale. */
  const checkout = () => {
    if (mayWork && (cart?.items?.length ?? 0) > 0) {
      setDialog('checkout');
    }
  };

  if (!can('pos.view')) {
    return (
      <ErrorState message="You do not have permission to open the till (pos.view)." />
    );
  }

  if (till.isLoading || !cart) {
    return till.isError ? (
      <ErrorState
        message="The till could not be opened. Check the active company and register."
        onRetry={() => till.refetch()}
      />
    ) : (
      <LoadingState label="Opening the till..." className="py-24" />
    );
  }

  const locked = !mayWork;

  return (
    <div className="flex h-[calc(100vh-7.5rem)] min-h-[540px] flex-col gap-2">
      <TillHeader
        term={term}
        onTerm={setTerm}
        searchRef={searchRef}
        onScan={scan}
        scanning={till.addLine.isPending}
        locked={locked}
        registerId={registerId}
      />

      {/* A cashier without register_sessions.view gets no bar: the drawer still
          takes its cash, it is simply not this role's to read. */}
      {maySeeShifts && (
        <ShiftBar
          shift={shift.data?.data ?? null}
          loading={shift.isPending}
          registerMissing={registerId === null}
        />
      )}

      <div className="flex min-h-0 flex-1 flex-col gap-2 lg:flex-row">
        <div className="flex min-h-0 flex-1 flex-col overflow-hidden rounded-lg border border-border bg-surface">
          <ProductGrid term={term} onAdd={addFromGrid} disabled={locked} />
        </div>

        <div className="flex min-h-0 w-full flex-col overflow-hidden rounded-lg border border-border lg:w-[380px] xl:w-[420px]">
          <CartPanel
            cart={cart}
            busy={till.busy}
            onQuantity={setQuantity}
            onDiscount={setLineDiscount}
            onNotes={setLineNotes}
            onRemove={(item) => till.removeLine.mutate(item.id)}
            onClear={() => till.clear.mutate()}
          />
        </div>
      </div>

      <TillFooter
        cart={cart}
        busy={till.busy}
        mayWork={mayWork}
        mayHold={mayHold}
        onCustomer={() => setDialog('customer')}
        onClearCustomer={() => till.patchCart.mutate({ clear_customer: true })}
        onDiscount={() => setDialog('discount')}
        onHold={() => setDialog('hold')}
        onRecall={() => setDialog('recall')}
        onCheckout={checkout}
      />

      <CustomerPickerModal
        open={dialog === 'customer'}
        onClose={closeDialog}
        cart={cart}
        busy={till.busy}
        onPick={pickCustomer}
      />

      <DiscountModal
        open={dialog === 'discount'}
        onClose={closeDialog}
        cart={cart}
        busy={till.busy}
        onSubmit={applyHeader}
      />

      <HoldDialog
        open={dialog === 'hold'}
        onClose={closeDialog}
        cart={cart}
        busy={till.hold.isPending}
        onHold={hold}
      />

      <RecallDialog
        open={dialog === 'recall'}
        onClose={closeDialog}
        busy={till.recall.isPending}
        onRecall={recall}
      />

      <CheckoutDialog
        open={dialog === 'checkout'}
        onClose={closeDialog}
        cart={cart}
      />
    </div>
  );
}

/**
 * The search box doubles as the scan target, so it stays focused: a scanner
 * types into whatever has focus and finishes with Enter.
 */
function TillHeader({
  term,
  onTerm,
  searchRef,
  onScan,
  scanning,
  locked,
  registerId,
}: {
  term: string;
  onTerm: (value: string) => void;
  searchRef: RefObject<HTMLInputElement | null>;
  onScan: () => void;
  scanning: boolean;
  locked: boolean;
  registerId: number | null;
}) {
  return (
    <header className="flex items-center gap-2">
      <div className="relative flex-1">
        <Input
          ref={searchRef}
          name="pos_search"
          icon="barcode-outline"
          autoComplete="off"
          placeholder="Scan a barcode, or search name, SKU, category, brand…"
          value={term}
          disabled={locked}
          onChange={(event) => onTerm(event.target.value)}
          onKeyDown={(event) => {
            if (event.key === 'Enter') {
              event.preventDefault();
              onScan();
            }
          }}
          className="h-11 pl-9 text-base"
          aria-label="Scan or search products"
        />
        {scanning && (
          <span className="absolute top-1/2 right-3 -translate-y-1/2 text-xs text-text-subtle">
            adding…
          </span>
        )}
      </div>

      {!registerId && (
        <p className="hidden shrink-0 text-xs text-warning lg:block">
          Pick a register in the top bar — carts are kept per till.
        </p>
      )}
    </header>
  );
}

/**
 * Customer | Discount | Tax | TOTAL | Checkout, in the order a sale is closed.
 *
 * Each figure is the server's, and the kbd hints are the same keys the dialog
 * opens on, so the bar teaches the shortcuts rather than competing with them.
 */
function TillFooter({
  cart,
  busy,
  mayWork,
  mayHold,
  onCustomer,
  onClearCustomer,
  onDiscount,
  onHold,
  onRecall,
  onCheckout,
}: {
  cart: NonNullable<ReturnType<typeof usePosCart>['cart']>;
  busy: boolean;
  mayWork: boolean;
  mayHold: boolean;
  onCustomer: () => void;
  onClearCustomer: () => void;
  onDiscount: () => void;
  onHold: () => void;
  onRecall: () => void;
  onCheckout: () => void;
}) {
  const items = cart.items?.length ?? 0;
  const ready = items > 0;

  return (
    <footer className="flex flex-wrap items-center gap-2 rounded-lg border border-border bg-surface px-3 py-2 shadow-sm">
      <CustomerFooterButton
        cart={cart}
        onOpen={onCustomer}
        onClear={onClearCustomer}
        busy={busy}
      />

      <DiscountFooterButton cart={cart} onOpen={onDiscount} />

      <div className="flex flex-col items-end leading-tight sm:items-start">
        <span className="text-[10px] tracking-wide text-text-subtle uppercase">Tax</span>
        <span className="font-mono text-sm text-text">{formatMoneyString(cart.tax_total)}</span>
      </div>

      <div className="ml-auto flex flex-col items-end leading-tight">
        <span className="text-[10px] tracking-wide text-text-subtle uppercase">
          Total · {items} {items === 1 ? 'line' : 'lines'}
        </span>
        <span className="font-mono text-xl font-semibold text-text">
          {formatMoneyString(cart.grand_total)}
        </span>
      </div>

      {mayHold && (
        <>
          <Button
            variant="outline"
            size="lg"
            icon="pause-circle-outline"
            onClick={onHold}
            disabled={busy || !ready}
            title="Park this cart and free the register (F8)"
          >
            Hold
          </Button>
          <Button
            variant="secondary"
            size="lg"
            icon="play-circle-outline"
            onClick={onRecall}
            disabled={busy}
            title="Bring a parked cart back (F9)"
          >
            Recall
          </Button>
        </>
      )}

      {/* The one button on the till that writes outside the cart: it posts the
          sale, its payments, its invoice number and its stock movement. */}
      <Button
        variant="primary"
        size="lg"
        icon="checkmark-circle-outline"
        onClick={onCheckout}
        disabled={!mayWork || !ready}
        title="Take payment and issue the invoice (F10)"
      >
        Checkout
        <kbd className="ml-1.5 hidden text-[10px] opacity-70 lg:inline">F10</kbd>
      </Button>
    </footer>
  );
}

