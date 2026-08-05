import { initDb } from './db';
import { route } from './ui/router';
import { isConnected, onNetworkChange } from './native/network';
import { syncNow } from './sync';
import { SYNC } from './config';

const banner = document.getElementById('offline-banner') as HTMLElement;

function updateOfflineBanner(connected: boolean): void {
  banner.classList.toggle('d-none', connected);
}

window.addEventListener('hashchange', () => {
  void route();
});

void (async () => {
  try {
    await initDb();
  } catch (e) {
    console.error('database init failed', e);
  }

  updateOfflineBanner(await isConnected());

  onNetworkChange((connected) => {
    updateOfflineBanner(connected);
    if (connected && SYNC.autoSyncOnNetwork) {
      void syncNow();
    }
  });

  if (SYNC.autoSyncOnStart) {
    void syncNow();
  }

  await route();
})();
