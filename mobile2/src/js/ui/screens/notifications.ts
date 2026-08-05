import { getNotifications, markNotificationRead } from '../../db/repos';
import { api, ENDPOINTS } from '../../api/client';

export async function renderNotifications(root: HTMLElement): Promise<void> {
  root.innerHTML = '<p class="text-muted">Loading…</p>';
  const items = await getNotifications();

  if (!items.length) {
    root.innerHTML = `<div class="alert alert-info">No notifications yet. New messages from the server will appear here.</div>`;
    return;
  }

  root.innerHTML = `
    <div class="list-group shadow-sm">
      ${items
        .map(
          (n) => `
        <button type="button" class="list-group-item list-group-item-action text-start ${n.is_read ? '' : 'list-group-item-primary'}" data-id="${n.id}" data-read="${n.is_read}">
          <div class="d-flex justify-content-between">
            <div class="fw-semibold">${n.title}</div>
            <div class="small text-muted">${(n.created_at ?? '').slice(0, 16).replace('T', ' ')}</div>
          </div>
          ${n.body ? `<div class="small">${n.body}</div>` : ''}
          ${n.is_read ? '' : '<span class="badge bg-primary mt-1">New</span>'}
        </button>`,
        )
        .join('')}
    </div>`;

  root.querySelectorAll('[data-id]').forEach((el) => {
    el.addEventListener('click', async () => {
      const id = Number(el.getAttribute('data-id'));
      if (el.getAttribute('data-read') !== '1') {
        await markNotificationRead(id);
        await api(ENDPOINTS.notificationRead(id), { method: 'POST' }).catch(() => {
          // offline — will re-sync read state on next download
        });
        await renderNotifications(root);
      }
    });
  });
}
