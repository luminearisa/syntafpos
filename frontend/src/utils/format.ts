import { clsx, type ClassValue } from 'clsx';

export function cn(...inputs: ClassValue[]): string {
  return clsx(inputs);
}

/**
 * Format a money amount. `currency` selects the display metadata; the default
 * mirrors the seeded IDR configuration (Rp, zero decimals) and any other code
 * falls back to a plain internationalized number.
 */
export function formatMoney(
  amount: number,
  currency = 'IDR',
  options: { symbol?: string; decimals?: number } = {}
): string {
  const { symbol = currency === 'IDR' ? 'Rp' : '', decimals = currency === 'IDR' ? 0 : 2 } = options;

  const formatted = new Intl.NumberFormat('id-ID', {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  }).format(amount);

  return symbol ? `${symbol} ${formatted}` : formatted;
}

export function formatDate(
  value: string | null | undefined,
  withTime = false
): string {
  if (!value) {
    return '-';
  }

  const date = new Date(value);

  if (Number.isNaN(date.getTime())) {
    return '-';
  }

  const datePart = new Intl.DateTimeFormat('id-ID', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
  }).format(date);

  if (!withTime) {
    return datePart;
  }

  const timePart = new Intl.DateTimeFormat('id-ID', {
    hour: '2-digit',
    minute: '2-digit',
  }).format(date);

  return `${datePart} ${timePart}`;
}

export function initials(name: string): string {
  return name
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? '')
    .join('');
}

export const labelFor = {
  branchType: (value: string | null | undefined): string => {
    const labels: Record<string, string> = {
      head_office: 'Head Office',
      outlet: 'Outlet',
      warehouse: 'Warehouse',
      other: 'Other',
    };

    if (!value) {
      return '-';
    }

    return labels[value] ?? value;
  },

  warehouseType: (value: string | null | undefined): string => {
    const labels: Record<string, string> = {
      main: 'Main',
      outlet: 'Outlet',
      production: 'Production',
      transit: 'Transit',
    };

    if (!value) {
      return '-';
    }

    return labels[value] ?? value;
  },

  status: (value: string | null | undefined): string => {
    if (!value) {
      return '-';
    }

    return value.charAt(0).toUpperCase() + value.slice(1);
  },

  productType: (value: string | null | undefined): string => {
    const labels: Record<string, string> = {
      simple: 'Simple',
      variable: 'Variable',
      service: 'Service',
      bundle: 'Bundle',
      raw_material: 'Raw Material',
      finished_good: 'Finished Good',
      consumable: 'Consumable',
    };

    if (!value) {
      return '-';
    }

    return labels[value] ?? value;
  },

  stockStatus: (value: string | null | undefined): string => {
    const labels: Record<string, string> = {
      in_stock: 'In Stock',
      low_stock: 'Low Stock',
      out_of_stock: 'Out of Stock',
      not_tracked: 'Not Tracked',
    };

    if (!value) {
      return '-';
    }

    return labels[value] ?? value;
  },

  unitType: (value: string | null | undefined): string => {
    const labels: Record<string, string> = {
      quantity: 'Quantity',
      length: 'Length',
      weight: 'Weight',
      volume: 'Volume',
      area: 'Area',
    };

    if (!value) {
      return '-';
    }

    return labels[value] ?? value;
  },

  taxType: (value: string | null | undefined): string => {
    const labels: Record<string, string> = {
      inclusive: 'Inclusive',
      exclusive: 'Exclusive',
    };

    if (!value) {
      return '-';
    }

    return labels[value] ?? value;
  },

  movementType: (value: string | null | undefined): string => {
    const labels: Record<string, string> = {
      opening: 'Opening Balance',
      purchase: 'Purchase',
      purchase_return: 'Purchase Return',
      sale: 'Sale',
      sale_return: 'Sale Return',
      transfer_in: 'Transfer In',
      transfer_out: 'Transfer Out',
      adjustment_in: 'Adjustment In',
      adjustment_out: 'Adjustment Out',
      production_in: 'Production In',
      production_out: 'Production Out',
      consumption: 'Consumption',
      stock_opname: 'Stock Opname',
    };

    if (!value) {
      return '-';
    }

    return labels[value] ?? value;
  },

  documentStatus: (value: string | null | undefined): string => {
    const labels: Record<string, string> = {
      draft: 'Draft',
      submitted: 'Submitted',
      counting: 'Counting',
      in_review: 'In Review',
      approved: 'Approved',
      sent: 'Sent',
      shipped: 'Shipped',
      partially_received: 'Partially Received',
      received: 'Received',
      completed: 'Completed',
      posted: 'Posted',
      converted: 'Converted',
      rejected: 'Rejected',
      cancelled: 'Cancelled',
      closed: 'Closed',
    };

    if (!value) {
      return '-';
    }

    return labels[value] ?? value;
  },

  adjustmentReason: (value: string | null | undefined): string => {
    const labels: Record<string, string> = {
      damage: 'Damage',
      lost: 'Lost',
      found: 'Found',
      expired: 'Expired',
      counting_error: 'Counting Error',
      other: 'Other',
    };

    if (!value) {
      return '-';
    }

    return labels[value] ?? value;
  },

  discountType: (value: string | null | undefined): string => {
    const labels: Record<string, string> = {
      percent: 'Percentage',
      amount: 'Fixed Amount',
    };

    if (!value) {
      return '-';
    }

    return labels[value] ?? value;
  },

  /**
   * Where a sale has got to. Kept apart from documentStatus on purpose: a sale
   * is measured in money taken, not in approval steps, so "Paid" and "Partially
   * Paid" have no equivalent on a purchase order.
   */
  saleStatus: (value: string | null | undefined): string => {
    const labels: Record<string, string> = {
      draft: 'Draft',
      pending_payment: 'Pending Payment',
      partially_paid: 'Partially Paid',
      paid: 'Paid',
      completed: 'Completed',
      cancelled: 'Cancelled',
    };

    if (!value) {
      return '-';
    }

    return labels[value] ?? value;
  },

  /**
   * A sales return's own status. Only `completed` moved stock, so the list can
   * colour it calm and leave the other two grey.
   */
  saleReturnStatus: (value: string | null | undefined): string => {
    const labels: Record<string, string> = {
      draft: 'Draft',
      completed: 'Completed',
      cancelled: 'Cancelled',
    };

    if (!value) {
      return '-';
    }

    return labels[value] ?? value;
  },

  /**
   * Where a refund has got to. `requested` and `approved` are waiting states,
   * `processing` is in flight, `completed` is money moved, and `failed`/`rejected`
   * are the two ways it did not.
   */
  refundStatus: (value: string | null | undefined): string => {
    const labels: Record<string, string> = {
      requested: 'Requested',
      approved: 'Approved',
      processing: 'Processing',
      completed: 'Completed',
      failed: 'Failed',
      rejected: 'Rejected',
    };

    if (!value) {
      return '-';
    }

    return labels[value] ?? value;
  },

  refundMethod: (value: string | null | undefined): string => {
    const labels: Record<string, string> = {
      cash: 'Cash',
      original_payment: 'Original payment',
      manual: 'Manual',
      gateway: 'Gateway',
    };

    if (!value) {
      return '-';
    }

    return labels[value] ?? value;
  },

  /**
   * A payment *channel*, not a shop's method name.
   *
   * A sale row already carries `method_name` — what the shop called it at the
   * counter — and prints that. This is the fallback for a row where that snapshot
   * is missing, and the catalogue words behind the nine kinds of money.
   */
  paymentChannel: (value: string | null | undefined): string => {
    const labels: Record<string, string> = {
      cash: 'Cash',
      bank_transfer: 'Bank transfer',
      debit: 'Debit card',
      credit_card: 'Credit card',
      qris: 'QRIS',
      e_wallet: 'E-wallet',
      virtual_account: 'Virtual account',
      customer_credit: 'Customer credit',
      other: 'Other',
    };

    if (!value) {
      return '-';
    }

    return labels[value] ?? value;
  },
};

/**
 * Format a decimal string straight from the API.
 *
 * The backend carries money as DECIMAL(20,4) and quantity as DECIMAL(20,6) so
 * the value is already exact; it only needs a thousands separator. Parsing
 * through Number is safe because the string has no currency symbol.
 */
export function formatDecimal(
  value: string | number | null | undefined,
  decimals = 2
): string {
  if (value === null || value === undefined || value === '') {
    return '0';
  }

  const number = typeof value === 'number' ? value : Number(value);

  if (!Number.isFinite(number)) {
    return '0';
  }

  return new Intl.NumberFormat('id-ID', {
    minimumFractionDigits: 0,
    maximumFractionDigits: decimals,
  }).format(number);
}

/**
 * Format a decimal money string (DECIMAL(20,4)) for display.
 */
export function formatMoneyString(
  value: string | number | null | undefined,
  currency = 'IDR'
): string {
  const symbol = currency === 'IDR' ? 'Rp' : '';

  return symbol ? `${symbol} ${formatDecimal(value, 0)}` : formatDecimal(value, 2);
}

/**
 * Normalise a decimal string for an editable input. Trailing zeros are kept
 * off so "1000.0000" shows as "1000", but any real fraction survives.
 */
export function decimalInputValue(value: string | number | null | undefined): string {
  if (value === null || value === undefined || value === '') {
    return '';
  }

  const text = String(value);

  return text.includes('.') ? text.replace(/\.?0+$/, '') : text;
}
