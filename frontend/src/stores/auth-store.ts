import { create } from 'zustand';
import { authApi } from '@/api/services';
import { clearContext, setContext, setToken } from '@/api/client';
import type { User } from '@/types';

interface BusinessScope {
  companyId: number | null;
  branchId: number | null;
  warehouseId: number | null;
  registerId: number | null;
}

interface AuthState {
  user: User | null;
  token: string | null;
  isAuthenticated: boolean;
  isLoading: boolean;
  error: string | null;

  scope: BusinessScope;

  login: (email: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
  fetchMe: () => Promise<void>;
  setScope: (scope: Partial<BusinessScope>) => void;
  can: (permission: string) => boolean;
  clearError: () => void;
}

function readStoredScope(): BusinessScope {
  const raw = localStorage.getItem('pos.context');
  const fallback: BusinessScope = {
    companyId: null,
    branchId: null,
    warehouseId: null,
    registerId: null,
  };

  if (!raw) {
    return fallback;
  }

  try {
    return { ...fallback, ...(JSON.parse(raw) as Partial<BusinessScope>) };
  } catch {
    return fallback;
  }
}

export const useAuthStore = create<AuthState>((set, get) => ({
  user: null,
  token: localStorage.getItem('pos.token'),
  isAuthenticated: !!localStorage.getItem('pos.token'),
  isLoading: false,
  error: null,

  scope: readStoredScope(),

  login: async (email: string, password: string) => {
    set({ isLoading: true, error: null });

    try {
      const response = await authApi.login(email, password);
      const { token, user } = response.data;

      setToken(token);

      // Default the active company to the first company the user can reach.
      const companyId = user.companies?.[0]?.id ?? null;

      const scope: BusinessScope = {
        companyId,
        branchId: user.branches?.find((b) => b.company_id === companyId)?.id ?? null,
        warehouseId:
          user.warehouses?.find((w) => w.company_id === companyId)?.id ?? null,
        registerId:
          user.registers?.find((r) => r.company_id === companyId)?.id ?? null,
      };

      setContext(scope);

      set({
        user,
        token,
        isAuthenticated: true,
        scope,
        isLoading: false,
        error: null,
      });
    } catch (error) {
      const message = extractErrorMessage(error, 'Login failed');

      set({ isLoading: false, error: message, isAuthenticated: false });
      throw new Error(message);
    }
  },

  logout: async () => {
    try {
      await authApi.logout();
    } catch {
      // The token may already be invalid; still clear local state.
    } finally {
      setToken(null);
      clearContext();

      set({
        user: null,
        token: null,
        isAuthenticated: false,
        scope: {
          companyId: null,
          branchId: null,
          warehouseId: null,
          registerId: null,
        },
      });
    }
  },

  fetchMe: async () => {
    set({ isLoading: true });

    try {
      const response = await authApi.me();
      set({ user: response.data.user, isLoading: false });
    } catch {
      setToken(null);
      clearContext();
      set({
        user: null,
        token: null,
        isAuthenticated: false,
        isLoading: false,
      });
    }
  },

  setScope: (partial) => {
    const next: BusinessScope = { ...get().scope, ...partial };

    // Changing company invalidates the child selections.
    if (partial.companyId !== undefined && partial.companyId !== get().scope.companyId) {
      next.branchId = null;
      next.warehouseId = null;
      next.registerId = null;
    }

    setContext(next);
    set({ scope: next });
  },

  can: (permission: string) => {
    const { user } = get();

    if (!user) {
      return false;
    }

    if (user.email === 'admin@example.com') {
      return true;
    }

    return (user.permissions ?? []).includes(permission);
  },

  clearError: () => set({ error: null }),
}));

function extractErrorMessage(error: unknown, fallback: string): string {
  if (error && typeof error === 'object' && 'isAxiosError' in error) {
    const axiosError = error as {
      response?: {
        data?: { message?: string; errors?: Record<string, string[]> };
        status?: number;
      };
    };

    if (axiosError.response?.data?.errors) {
      const first = Object.values(axiosError.response.data.errors)[0];
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
