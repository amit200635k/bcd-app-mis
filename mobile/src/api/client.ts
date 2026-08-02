import {API_BASE_URL} from '../config';
import {ApiResponse} from '../types/api';
import {SessionStore} from './session';

export class ApiError extends Error {
  readonly status: number;
  readonly errors: Record<string, string[]> | undefined;

  constructor(status: number, message: string, errors?: Record<string, string[]>) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.errors = errors;
  }

  get fieldMessage(): string {
    if (this.errors) {
      const first = Object.values(this.errors)[0];
      if (first && first.length > 0) {
        return first[0];
      }
    }
    return this.message;
  }
}

let refreshing: Promise<string | null> | null = null;

async function doRefresh(): Promise<string | null> {
  const refresh = await SessionStore.readRefreshToken();
  if (!refresh) {
    return null;
  }
  try {
    const res = await fetch(`${API_BASE_URL}/auth/refresh`, {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({refresh_token: refresh}),
    });
    if (!res.ok) {
      return null;
    }
    const body = (await res.json()) as ApiResponse<{access_token: string; refresh_token: string; expires_in: number}>;
    await SessionStore.save(
      {
        access_token: body.data.access_token,
        refresh_token: body.data.refresh_token,
        expires_in: body.data.expires_in,
      },
      await SessionStore.readProfile() ?? {id: 0, username: '', full_name: '', roles: []},
    );
    return body.data.access_token;
  } catch {
    return null;
  }
}

/** Single-flight refresh so concurrent 401s share one token rotation. */
function refreshAccessToken(): Promise<string | null> {
  if (!refreshing) {
    refreshing = doRefresh().finally(() => {
      refreshing = null;
    });
  }
  return refreshing;
}

interface RequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'DELETE';
  body?: unknown;
  /** Do not attach the Bearer token (e.g. login). */
  public?: boolean;
  /** Automatically refresh once on 401 and retry. */
  retry?: boolean;
  timeoutMs?: number;
}

export async function apiRequest<T>(
  path: string,
  {method = 'GET', body, public: isPublic = false, retry = true, timeoutMs = 20000}: RequestOptions = {},
): Promise<T> {
  const headers: Record<string, string> = {'Content-Type': 'application/json'};
  if (!isPublic) {
    const token = await SessionStore.readAccessToken();
    if (token) {
      headers.Authorization = `Bearer ${token}`;
    }
  }

  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  try {
    const res = await fetch(`${API_BASE_URL}${path}`, {
      method,
      headers,
      body: body === undefined ? undefined : JSON.stringify(body),
      signal: controller.signal,
    });

    if (res.status === 401 && !isPublic && retry) {
      const fresh = await refreshAccessToken();
      if (fresh) {
        return apiRequest<T>(path, {...{method, body, isPublic, retry: false, timeoutMs}});
      }
    }

    let payload: ApiResponse<T> | null = null;
    try {
      payload = (await res.json()) as ApiResponse<T>;
    } catch {
      // Non-JSON body (e.g. server error page).
    }

    if (!res.ok) {
      throw new ApiError(
        res.status,
        payload?.message ?? `Request failed with status ${res.status}`,
        payload?.errors,
      );
    }
    return (payload?.data ?? payload) as T;
  } catch (err) {
    if (err instanceof ApiError) {
      throw err;
    }
    if (err instanceof Error && err.name === 'AbortError') {
      throw new ApiError(408, 'Request timed out. Please check your connection.');
    }
    throw new ApiError(0, 'Network error. Please check your connection and try again.');
  } finally {
    clearTimeout(timer);
  }
}

export const api = {
  get: <T>(path: string, opts?: Omit<RequestOptions, 'method' | 'body'>) => apiRequest<T>(path, {method: 'GET', ...opts}),
  post: <T>(path: string, body?: unknown, opts?: Omit<RequestOptions, 'method' | 'body'>) =>
    apiRequest<T>(path, {method: 'POST', body, ...opts}),
};
