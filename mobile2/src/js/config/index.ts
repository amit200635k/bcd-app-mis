export const APP_VERSION = '0.1.0';

export const APP_NAME = 'Building Survey MIS';

/**
 * Backend API base URL.
 * - Dev (LDPlayer/emulator): `adb reverse tcp:8080 tcp:81` maps the guest's
 *   127.0.0.1:8080 to the host's XAMPP Apache on port 81 (device-side ports
 *   below 1024 are blocked for adb reverse). LDPlayer's NAT does NOT expose
 *   the host at 10.0.2.2, so the loopback reverse tunnel is used.
 * - Physical device: point this at your machine's LAN IP.
 * - Release: HTTPS endpoint only (cleartext is disabled in release builds).
 *
 * This value is the compiled-in fallback. At runtime, the app checks
 * `window.__BCD_CONFIG__.API_BASE_URL` first (set via public/custom.js).
 */
export const API_BASE_URLX =
  'http://localhost:81/bcd-app/api/v1';
export const API_BASE_URL = window.__BCD_CONFIG__.API_BASE_URL ?? 'https://jswm.jharkhand.gov.in/bcdapp/api/v1';
export const API_BASE_URL_Live =
  'https://jswm.jharkhand.gov.in/bcdapp/api/v1';


export const API_BASE_URL_Livex =
  'https://jswm.jharkhand.gov.in/bcdapp/api/v1';
/** Runtime API URL — checks custom.js override first, falls back to compiled constant. */
export function getApiBaseUrl(): string {
  try {
    const runtime = (window as unknown as Record<string, unknown>).__BCD_CONFIG__;
    if (runtime && typeof runtime === 'object' && 'API_BASE_URL' in runtime) {
      console.log('Using runtime API_BASE_URL:', (runtime as Record<string, string>).API_BASE_URL);
      return String((runtime as Record<string, string>).API_BASE_URL);
    }
  } catch { /* window not available in tests */ }
  return API_BASE_URL;
}

export const SYNC = {
  maxRetries: 5,
  retryBaseDelayMs: 5_000,
  autoSyncOnStart: true,
  autoSyncOnNetwork: true,
};
