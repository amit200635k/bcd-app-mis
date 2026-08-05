import { currentUser } from '../../auth';
import { getForms } from '../../db/repos';
import { getRecords, getUnreadCount, getQueueStats } from '../../db/repos';
import { navigate } from '../router';

export async function renderHome(root: HTMLElement): Promise<void> {
  const user = await currentUser();
  const name = user?.full_name || user?.username || 'Surveyor';
  const role = user?.role || '';

  root.innerHTML = `
    <div class="mb-4">
      <h4 class="mb-0">Hello, ${name}</h4>
      <div class="text-muted small">${role ? `${role} · ` : ''}All data is stored on this device and syncs automatically.</div>
    </div>
    <div class="row g-3" id="home-menu"></div>`;

  const menu = root.querySelector<HTMLElement>('#home-menu')!;

  const [forms, records, unread, queue] = await Promise.all([
    getForms(),
    getRecords(),
    getUnreadCount(),
    getQueueStats().catch(() => ({ pending: 0, synced: 0, failed: 0 })),
  ]);

  const items: Array<{ icon: string; label: string; sub: string; to: string; badge?: string; danger?: boolean }> = [
    { icon: '📋', label: 'Surveys', sub: `${forms.length} form${forms.length === 1 ? '' : 's'} available`, to: 'forms' },
    { icon: '🗂️', label: 'My Records', sub: `${records.length} on this device`, to: 'records' },
    { icon: '🔄', label: 'Sync', sub: `${queue.pending} pending · ${queue.failed} failed`, to: 'sync', badge: queue.failed > 0 ? `${queue.failed} failed` : queue.pending > 0 ? `${queue.pending} pending` : 'All synced' },
    { icon: '🔔', label: 'Notifications', sub: 'Server messages', to: 'notifications', badge: unread > 0 ? String(unread) : undefined },
    { icon: '⚙️', label: 'Settings', sub: 'Server, version, sign out', to: 'settings' },
  ];

  menu.innerHTML = items
    .map(
      (it) => `
      <div class="col-6 col-md-4">
        <button type="button" class="card h-100 w-100 text-start shadow-sm border-0 p-3 menu-card" data-to="${it.to}">
          <div class="fs-2 mb-2">${it.icon}</div>
          <div class="fw-semibold">${it.label}</div>
          <div class="text-muted small">${it.sub}</div>
          ${it.badge ? `<span class="badge ${it.danger ? 'bg-danger' : 'bg-secondary'} mt-2">${it.badge}</span>` : ''}
        </button>
      </div>`,
    )
    .join('');

  menu.querySelectorAll('.menu-card').forEach((el) => {
    el.addEventListener('click', () => navigate(el.getAttribute('data-to') ?? ''));
  });
}
