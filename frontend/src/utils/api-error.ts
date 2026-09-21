/**
 * Extracts the first human-readable message from a failed API call.
 *
 * The backend rejects with a standard envelope: validation failures carry a
 * keyed `errors` map, everything else carries a plain `message`. Callers get
 * back a sentence they can show in a toast or under a form field.
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
 * Maps a rejected API call's `errors` object onto individual form fields.
 * Returns an empty object when the failure was not a validation error.
 */
export function apiFieldErrors(error: unknown): Record<string, string> {
  if (error && typeof error === 'object' && 'isAxiosError' in error) {
    const axiosError = error as {
      response?: { data?: { errors?: Record<string, string[]> } };
    };

    const errors = axiosError.response?.data?.errors;

    if (errors) {
      return Object.fromEntries(
        Object.entries(errors)
          .filter(([, messages]) => Array.isArray(messages) && messages.length > 0)
          .map(([field, messages]) => [field, (messages as string[])[0] ?? ''])
      );
    }
  }

  return {};
}
