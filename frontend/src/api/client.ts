import axios, { type AxiosInstance, type AxiosRequestConfig, type AxiosResponse } from 'axios';
import type { ApiResponse } from '@/types';

const STORAGE_KEY = 'pos.token';

/**
 * Central API client.
 *
 * The token is persisted by the auth store; this module only reads it so the
 * transport layer stays free of business state.
 */
export const api: AxiosInstance = axios.create({
  baseURL: '/api/v1',
  headers: {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  },
  timeout: 30_000,
});

api.interceptors.request.use((config) => {
  const token = localStorage.getItem(STORAGE_KEY);
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }

  const context = readContext();
  if (context) {
    Object.entries(context).forEach(([key, value]) => {
      if (value !== null && value !== undefined) {
        config.headers[key] = String(value);
      }
    });
  }

  return config;
});

api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      // Token rejected: clear state and route the user back to login.
      localStorage.removeItem(STORAGE_KEY);
      localStorage.removeItem('pos.context');

      if (window.location.pathname !== '/login') {
        window.location.assign('/login');
      }
    }

    return Promise.reject(error);
  }
);

const CONTEXT_HEADERS: Record<string, string> = {
  companyId: 'X-Company-Id',
  branchId: 'X-Branch-Id',
  warehouseId: 'X-Warehouse-Id',
  registerId: 'X-Register-Id',
};

interface ContextState {
  companyId: number | null;
  branchId: number | null;
  warehouseId: number | null;
  registerId: number | null;
}

function readContext(): Record<string, number | null> {
  const raw = localStorage.getItem('pos.context');
  if (!raw) {
    return {};
  }

  try {
    const parsed = JSON.parse(raw) as ContextState;

    return Object.fromEntries(
      Object.entries(CONTEXT_HEADERS).map(([key, header]) => [
        header,
        parsed[key as keyof ContextState] ?? null,
      ])
    );
  } catch {
    return {};
  }
}

/**
 * Type-safe request helper that unwraps the standard API envelope.
 */
export async function request<T>(config: AxiosRequestConfig): Promise<ApiResponse<T>> {
  const response: AxiosResponse<ApiResponse<T>> = await api(config);

  return response.data;
}

export function setToken(token: string | null): void {
  if (token) {
    localStorage.setItem(STORAGE_KEY, token);
  } else {
    localStorage.removeItem(STORAGE_KEY);
  }
}

export function setContext(context: ContextState): void {
  localStorage.setItem('pos.context', JSON.stringify(context));
}

export function clearContext(): void {
  localStorage.removeItem('pos.context');
}
