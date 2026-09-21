import { initDb } from './db';
import { route, navigate, closeDrawer } from './ui/router';
import { logout } from './auth';
import { isConnected, onNetworkChange } from './native/network';
import { migrateAttachmentsFromCache } from './native/media';
import { syncNow } from './sync';
import { SYNC } from './config';

const banner = document.getElementById('offline-banner') as HTMLElement;
const overlayEl = document.getElementById('drawer-overlay') as HTMLElement;

function updateOfflineBanner(connected: boolean): void {
  banner.classList.toggle('d-none', connected);
}

function initDrawer(): void {
  overlayEl.addEventListener('click', closeDrawer);

  document.querySelectorAll('.drawer-item[data-to]').forEach((el) => {
    el.addEventListener('click', (e) => {
      e.preventDefault();
      const to = el.getAttribute('data-to');
      if (to) navigate(to);
    });
  });

  const logoutBtn = document.getElementById('btn-drawer-logout');
  if (logoutBtn) {
    logoutBtn.addEventListener('click', () => {
      closeDrawer();
      void logout().finally(() => navigate('login'));
    });
  }
}

window.addEventListener('hashchange', () => {
  void route();
});

void (async () => {
  initDrawer();

  try {
    await initDb();
  } catch (e) {
    console.error('database init failed', e);
  }

  try {
    // One-time: move any attachments still in the evictable Cache dir to Data.
    await migrateAttachmentsFromCache();
  } catch (e) {
    console.error('attachment migration failed', e);
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
