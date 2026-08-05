import { Device } from '@capacitor/device';
import { api, ENDPOINTS } from '../api/client';
import { getSetting, setSetting } from '../db/repos';
import { APP_VERSION } from '../config';

let cachedDeviceId: string | null = null;

/** Stable per-install identifier (persisted so re-login keeps the same device). */
export async function getDeviceId(): Promise<string> {
  if (cachedDeviceId) {
    return cachedDeviceId;
  }
  const stored = await getSetting('device_id');
  if (stored) {
    cachedDeviceId = stored;
    return stored;
  }
  const info = await Device.getId();
  const id = info.identifier ?? `dev-${Date.now().toString(36)}`;
  await setSetting('device_id', id);
  cachedDeviceId = id;
  return cachedDeviceId;
}

export async function getDeviceInfo(): Promise<{
  device_name: string;
  platform: string;
  os_version: string;
  app_version: string;
}> {
  const info = await Device.getInfo();
  return {
    device_name: info.name ?? info.model ?? 'android',
    platform: info.platform ?? 'android',
    os_version: info.osVersion ?? '',
    app_version: APP_VERSION,
  };
}

/** Register (or re-register) this device so the server's sync queue targets it. */
export async function registerDevice(): Promise<void> {
  const deviceId = await getDeviceId();
  try {
    await api(ENDPOINTS.devices, {
      method: 'POST',
      body: { device_id: deviceId, ...(await getDeviceInfo()) },
    });
  } catch (e) {
    console.warn('device registration failed', e);
  }
}