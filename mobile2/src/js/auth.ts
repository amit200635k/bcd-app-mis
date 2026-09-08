import { api, ENDPOINTS, ApiError } from './api/client';
import { clearTokens, getTokens, saveTokens } from './api/session';
import type { TokenResponse } from './api/types';
import { clearUsers, getUser, saveUser, adoptOrphanRecords, purgeRecordsForUserSwitch, setSetting, getSetting, type LocalUser } from './db/repos';
import { getDeviceId, registerDevice } from './native/device';
import { fetchLocationScope, downloadAll } from './download';
import { audit } from './db/repos';

/** True on first ever login of this install (triggers a forced full download). */
export async function isFirstLaunch(): Promise<boolean> {
  const done = await getSetting('bootstrapped');
  return done !== '1';
}

export async function markBootstrapped(): Promise<void> {
  await setSetting('bootstrapped', '1');
}

/**
 * Login + bootstrap: persist tokens, user profile + location scope,
 * register the device and download all offline resources (forced on first
 * launch, incremental afterwards).
 */
export async function login(username: string, password: string): Promise<void> {
  const deviceId = await getDeviceId();
  const res = await api<TokenResponse>(ENDPOINTS.auth.login, {
    method: 'POST',
    auth: false,
    body: { username, password, device_id: deviceId },
  });
  await saveTokens({ access_token: res.access_token, refresh_token: res.refresh_token });

  const scope = await fetchLocationScope();
  const previous = await getUser();
  await saveUser({
    id: res.user.id,
    username: res.user.username,
    full_name: res.user.full_name ?? null,
    role: res.user.role ?? null,
    scope_json: scope ? JSON.stringify(scope) : null,
    profile_json: JSON.stringify(res.user),
  });

  // Data isolation across sign-ins: when a different user logs in on this
  // device, remove the previous user's survey data so records + sync show only
  // the current user's entries. Legacy rows with no owner are adopted to the
  // current user.
  if (previous && previous.id !== res.user.id) {
    await purgeRecordsForUserSwitch(res.user.id);
  }
  await adoptOrphanRecords(res.user.id);

  const first = await isFirstLaunch();
  await registerDevice();
  await downloadAll(first);
  await markBootstrapped();
  await audit('auth.login', { username: res.user.username, first });
}

/** Resolve the signed-in user (from local cache; /auth/me fallback). */
export async function currentUser(): Promise<LocalUser | null> {
  const cached = await getUser();
  if (cached) return cached;
  try {
    const me = await api<Record<string, unknown>>(ENDPOINTS.auth.me);
    const u = {
      id: Number(me.id ?? 0),
      username: String(me.username ?? ''),
      full_name: me.full_name ? String(me.full_name) : null,
      role: Array.isArray(me.roles) ? (me.roles[0] as string) ?? null : null,
      profile_json: JSON.stringify(me),
    };
    await saveUser(u);
    return u;
  } catch {
    return null;
  }
}

export function isLoggedIn(): Promise<boolean> {
  return getTokens().then((t) => t !== null);
}

export async function logout(): Promise<void> {
  try {
    const tokens = await getTokens();
    if (tokens) {
      await api(ENDPOINTS.auth.logout, { method: 'POST', body: { refresh_token: tokens.refresh_token } });
    }
  } catch (e) {
    if (!(e instanceof ApiError && e.status === 401)) {
      console.warn('logout api failed', e);
    }
  }
  await clearTokens();
  await clearUsers();
  await audit('auth.logout', {});
}
