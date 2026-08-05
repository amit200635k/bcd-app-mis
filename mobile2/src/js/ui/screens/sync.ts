import { getQueueStats, getAllQueue } from '../../db/repos';
import { syncNow, isSyncing } from '../../sync';
import { api, ENDPOINTS } from '../../api/client';
import type { SyncStatus } from '../../api/types';
import { isConnected } from '../../native/network';

function statusBadge(status: string): string {
  const cls =
    status === 'synced' ? 'bg-success' : status === 'failed' ? 'bg-danger' : status === 'syncing' ? 'bg-info' : 'bg-warning';
  return `<span class="badge ${cls}">${status}</span>`;
}

export async function renderSync(root: HTMLElement): Promise<void> {
  root.innerHTML = `
    <div class="card shadow-sm mb-3">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <div class="fs-4 fw-semibold" id="sync-stats">…</div>
            <div class="text-muted small" id="sync-sub">Checking…</div>
          </div>
          <button class="btn btn-primary" id="btn-sync-now">Sync now</button>
        </div>
      </div>
    </div>
    <div class="card shadow-sm">
      <div class="card-header bg-light fw-semibold">Sync queue</div>
      <div class="card-body p-0" id="sync-queue"><div class="p-3 text-muted">Loading…</div></div>
    </div>`;

  const statsEl = root.querySelector<HTMLElement>('#sync-stats')!;
  const subEl = root.querySelector<HTMLElement>('#sync-sub')!;
  const queueEl = root.querySelector<HTMLElement>('#sync-queue')!;
  const btn = root.querySelector<HTMLButtonElement>('#btn-sync-now')!;

  const refresh = async (): Promise<void> => {
    const [stats, items, connected] = await Promise.all([
      getQueueStats(),
      getAllQueue(),
      isConnected(),
    ]);
    statsEl.textContent = `${stats.pending} pending · ${stats.synced} synced · ${stats.failed} failed`;
    subEl.textContent = `${connected ? 'Online' : 'Offline'}${isSyncing() ? ' · syncing…' : ''}`;

    if (items.length === 0) {
      queueEl.innerHTML = '<div class="p-3 text-muted">Nothing to sync — all changes are up to date.</div>';
      return;
    }
    queueEl.innerHTML = `<div class="table-responsive">
      <table class="table table-sm table-hover mb-0">
        <thead><tr><th>Action</th><th>Record</th><th>Status</th><th>Retries</th><th>Error</th></tr></thead>
        <tbody>${items
          .map(
            (it) => `<tr>
              <td>${it.action}</td>
              <td class="small font-monospace">${it.record_uuid.slice(0, 13)}…</td>
              <td>${statusBadge(it.status)}</td>
              <td>${it.retry_count ?? 0}</td>
              <td class="small text-danger">${it.error ?? ''}</td>
            </tr>`,
          )
          .join('')}
        </tbody>
      </table>
    </div>`;
  };

  btn.addEventListener('click', async () => {
    btn.disabled = true;
    btn.textContent = 'Syncing…';
    await syncNow();
    try {
      await api<SyncStatus>(ENDPOINTS.syncStatus);
    } catch {
      // server status is best-effort here
    }
    await refresh();
    btn.disabled = false;
    btn.textContent = 'Sync now';
  });

  await refresh();
}
