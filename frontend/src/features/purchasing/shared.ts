/**
 * Helpers shared by the purchasing pages and their form drawers.
 *
 * Kept local to the purchasing feature so the four documents stay consistent
 * without leaking purchase-specific concerns into the rest of the app.
 */

/**
 * Pull a single human-readable message out of an API error. Validation errors
 * are flattened to their first message so a toast never shows raw JSON.
 */
export function apiErrorMessage(error: unknown, fallback: string): string {
  if (error && typeof error === 'object' && 'isAxiosError' in error) {
    const axiosError = error as {
      response?: { data?: { message?: string; errors?: Record<string, string[]> } };
    };

    const errors = axiosError.response?.data?.errors;
    if (errors) {
      const first = Object.values(errors)[0];
      if (first && first.length > 0) {
        return first[0] ?? fallback;
      }
    }

    if (axiosError.response?.data?.message) {
      return axiosError.response.data.message;
    }
  }

  if (error instanceof Error) {
    return error.message;
  }

  return fallback;
}

/**
 * Flatten a validation failure into a per-field map for inline form errors,
 * plus the top-level message for the toast.
 */
export function extractApiErrors(error: unknown): {
  fieldErrors: Record<string, string>;
  message: string;
} {
  if (error && typeof error === 'object' && 'isAxiosError' in error) {
    const axiosError = error as {
      response?: { data?: { message?: string; errors?: Record<string, string[]> } };
    };
    const data = axiosError.response?.data;
    const fieldErrors: Record<string, string> = {};

    if (data?.errors) {
      Object.entries(data.errors).forEach(([key, messages]) => {
        fieldErrors[key] = messages[0] ?? '';
      });
    }

    return { fieldErrors, message: data?.message ?? 'Validation failed' };
  }

  if (error instanceof Error) {
    return { fieldErrors: {}, message: error.message };
  }

  return { fieldErrors: {}, message: 'Something went wrong' };
}

/**
 * Send an empty string as null so the backend's nullable rules accept it.
 */
export function emptyToNull(value: string): string | null {
  return value.trim() === '' ? null : value;
}

/**
 * Parse a decimal input into a number for preview maths. A non-numeric or
 * empty input is zero rather than NaN so the preview never renders "NaN".
 */
export function toNumber(value: string | number | null | undefined): number {
  if (value === null || value === undefined) {
    return 0;
  }

  const parsed = typeof value === 'number' ? value : Number(String(value).trim());

  return Number.isFinite(parsed) ? parsed : 0;
}

/**
 * The discount spelling the API accepts on a purchase order line. The shared
 * catalog type spells the persisted enum differently, so the drawers talk to
 * the API in its own terms.
 */
export type ApiDiscountType = 'amount' | 'percent';

export function normalizeDiscountType(
  value: string | null | undefined
): ApiDiscountType {
  return value === 'percent' || value === 'percentage' ? 'percent' : 'amount';
}

export interface LinePreview {
  gross: number;
  discount: number;
  net: number;
  tax: number;
  total: number;
}

/**
 * Preview-only line maths (spec §46).
 *
 * These numbers exist purely so the user can see what a line is doing while
 * they type. The backend's PurchaseCalculationService recomputes every figure
 * server-side in fixed point and overwrites whatever the client sends, so the
 * return value here is never authoritative and never submitted.
 */
export function previewLine(
  quantity: number,
  unitPrice: number,
  discountType: ApiDiscountType,
  discountValue: number,
  taxRate: number
): LinePreview {
  const gross = quantity * unitPrice;

  const discount =
    discountType === 'percent'
      ? (gross * Math.max(discountValue, 0)) / 100
      : Math.max(discountValue, 0);

  const net = Math.max(gross - discount, 0);
  const tax = (net * Math.max(taxRate, 0)) / 100;

  return { gross, discount, net, tax, total: net + tax };
}
