export const APP_VERSION = '0.1.0';

export const APP_NAME = 'BCD Survey';

/**
 * Backend API base URL.
 * - Dev (LDPlayer/emulator): `adb reverse tcp:8080 tcp:81` maps the guest's
 *   127.0.0.1:8080 to the host's XAMPP Apache on port 81 (device-side ports
 *   below 1024 are blocked for adb reverse). LDPlayer's NAT does NOT expose
 *   the host at 10.0.2.2, so the loopback reverse tunnel is used.
 * - Physical device: point this at your machine's LAN IP.
 * - Release: HTTPS endpoint only (cleartext is disabled in release builds).
 */
export const API_BASE_URL =
  'http://127.0.0.1:8080/bcd-app/api/v1';

export const SYNC = {
  maxRetries: 5,
  retryBaseDelayMs: 5_000,
  autoSyncOnStart: true,
  autoSyncOnNetwork: true,
};
