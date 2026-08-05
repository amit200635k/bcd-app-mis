import { SecureStorage } from '@aparajita/capacitor-secure-storage';
import { Preferences } from '@capacitor/preferences';
import { Capacitor } from '@capacitor/core';

const ACCESS_KEY = 'bcd.access_token';
const REFRESH_KEY = 'bcd.refresh_token';

export interface TokenPair {
  access_token: string;
  refresh_token: string;
}

async function nativeGet(key: string): Promise<string | null> {
  try {
    return await SecureStorage.getItem(key);
  } catch {
    return null;
  }
}

async function nativeSet(key: string, value: string): Promise<boolean> {
  try {
    await SecureStorage.setItem(key, value);
    return true;
  } catch {
    return false;
  }
}

async function nativeRemove(key: string): Promise<void> {
  try {
    await SecureStorage.removeItem(key);
  } catch {
    // ignore
  }
}

/** Tokens live in the Keystore-backed secure storage on Android; fall back to Preferences elsewhere. */
export async function saveTokens(tokens: TokenPair): Promise<void> {
  if (Capacitor.isNativePlatform()) {
    const ok =
      (await nativeSet(ACCESS_KEY, tokens.access_token)) &&
      (await nativeSet(REFRESH_KEY, tokens.refresh_token));
    if (ok) {
      return;
    }
  }
  await Preferences.set({ key: ACCESS_KEY, value: tokens.access_token });
  await Preferences.set({ key: REFRESH_KEY, value: tokens.refresh_token });
}

export async function getTokens(): Promise<TokenPair | null> {
  if (Capacitor.isNativePlatform()) {
    const [access, refresh] = await Promise.all([nativeGet(ACCESS_KEY), nativeGet(REFRESH_KEY)]);
    if (access && refresh) {
      return { access_token: access, refresh_token: refresh };
    }
  }
  const access = await Preferences.get({ key: ACCESS_KEY });
  const refresh = await Preferences.get({ key: REFRESH_KEY });
  return access.value && refresh.value ? { access_token: access.value, refresh_token: refresh.value } : null;
}

export async function clearTokens(): Promise<void> {
  if (Capacitor.isNativePlatform()) {
    await Promise.all([nativeRemove(ACCESS_KEY), nativeRemove(REFRESH_KEY)]);
    return;
  }
  await Preferences.remove({ key: ACCESS_KEY });
  await Preferences.remove({ key: REFRESH_KEY });
}
