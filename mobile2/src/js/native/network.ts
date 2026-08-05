import { Network } from '@capacitor/network';

const listeners: Array<(connected: boolean) => void> = [];
let registered = false;

export async function isConnected(): Promise<boolean> {
  try {
    const status = await Network.getStatus();
    return status.connected === true;
  } catch {
    return typeof navigator !== 'undefined' ? navigator.onLine : false;
  }
}

/** Subscribe to connectivity changes (idempotent native listener). */
export function onNetworkChange(cb: (connected: boolean) => void): void {
  listeners.push(cb);
  if (registered) {
    return;
  }
  registered = true;
  void Network.addListener('networkStatusChange', (status) => {
    const connected = status.connected === true;
    for (const l of listeners) l(connected);
  });
}