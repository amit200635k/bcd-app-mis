import { API_BASE_URL } from '../config';
import { ENDPOINTS } from './endpoints';
import { clearTokens, getTokens, saveTokens } from './session';

export class ApiError extends Error {
  readonly status: number;
  readonly errors?: Record<string, string[]>;

  constructor(message: string, status: number, errors?: Record<string, string[]>) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.errors = errors;
  }
}

interface RequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'DELETE';
  body?: unknown;
  formData?: FormData;
  /** Set false to skip the Authorization header (login/refresh). */
  auth?: boolean;
  timeoutMs?: number;
}

interface ApiResponse<T> {
  success: boolean;
  data: T;
  message?: string;
  errors?: Record<string, string[]>;
}

let refreshPromise: Promise<boolean> | null = null;

/** Single-flight token refresh. Returns true when a new access token was obtained. */
export async function refreshAccessToken(): Promise<boolean> {
  if (refreshPromise) {
    return refreshPromise;
  }
  refreshPromise = (async () => {
    const tokens = await getTokens();
    if (!tokens?.refresh_token) {
      return false;
    }
    try {
      const res = await rawFetch<{ access_token: string; refresh_token: string }>(
        ENDPOINTS.auth.refresh,
        { method: 'POST', body: { refresh_token: tokens.refresh_token }, auth: false },
      );
      await saveTokens({
        access_token: res.access_token,
        refresh_token: res.refresh_token ?? tokens.refresh_token,
      });
      return true;
    } catch {
      await clearTokens();
      return false;
    } finally {
      refreshPromise = null;
    }
  })();
  return refreshPromise;
}

async function rawFetch<T>(path: string, opts: RequestOptions): Promise<T> {
  const url = API_BASE_URL + path;
  const headers: Record<string, string> = {};
  if (opts.formData) {
    // browser sets multipart boundary automatically
  } else {
    headers['Content-Type'] = 'application/json';
  }
  if (opts.auth !== false) {
    const tokens = await getTokens();
    if (tokens?.access_token) {
      headers.Authorization = `Bearer ${tokens.access_token}`;
    }
  }

  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), opts.timeoutMs ?? 45_000);
  try {
    const res = await fetch(url, {
      method: opts.method ?? 'GET',
      headers,
      body: opts.formData ?? (opts.body !== undefined ? JSON.stringify(opts.body) : undefined),
      signal: controller.signal,
    });

    let payload: ApiResponse<T> | null = null;
    const text = await res.text();
    if (text) {
      try {
        payload = JSON.parse(text) as ApiResponse<T>;
      } catch {
        // non-JSON body — treat as error below
      }
    }

    if (!res.ok || payload?.success === false) {
      throw new ApiError(
        payload?.message ?? `HTTP ${res.status}`,
        res.status,
        payload?.errors,
      );
    }
    if (!payload) {
      throw new ApiError('Empty response from server.', res.status);
    }
    return payload.data;
  } finally {
    clearTimeout(timeout);
  }
}

/**
 * Authenticated fetch with single-flight refresh + one retry on 401.
 * Returns `data` from the envelope (or the raw body for non-envelope endpoints).
 */
export async function api<T = unknown>(path: string, opts: RequestOptions = {}): Promise<T> {
  try {
    return await rawFetch<T>(path, opts);
  } catch (e) {
    if (e instanceof ApiError && e.status === 401 && opts.auth !== false) {
      const refreshed = await refreshAccessToken();
      if (refreshed) {
        return rawFetch<T>(path, opts);
      }
      throw e;
    }
    throw e;
  }
}

export { ENDPOINTS };
