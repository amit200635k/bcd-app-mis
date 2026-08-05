import { currentUser } from '../../auth';
import { getDeviceId } from '../../native/device';
import { API_BASE_URL, APP_NAME, APP_VERSION } from '../../config';
import { navigate } from '../router';

export async function renderSettings(root: HTMLElement): Promise<void> {
  const [user, deviceId] = await Promise.all([currentUser(), getDeviceId()]);

  root.innerHTML = `
    <div class="card shadow-sm mb-3">
      <div class="card-body">
        <h5 class="card-title">${APP_NAME} v${APP_VERSION}</h5>
        <dl class="row small mb-0">
          <dt class="col-5 text-muted">Signed in as</dt>
          <dd class="col-7 mb-1">${user?.full_name ?? user?.username ?? '—'}</dd>
          <dt class="col-5 text-muted">Username</dt>
          <dd class="col-7 mb-1">${user?.username ?? '—'}</dd>
          <dt class="col-5 text-muted">Device id</dt>
          <dd class="col-7 mb-1 font-monospace">${deviceId}</dd>
          <dt class="col-5 text-muted">Server</dt>
          <dd class="col-7 mb-0 font-monospace text-break">${API_BASE_URL}</dd>
        </dl>
      </div>
    </div>
    <div class="card shadow-sm">
      <div class="card-body">
        <h6 class="card-title">Data</h6>
        <p class="small text-muted">
          All records are stored on this device (SQLite) and synced to the
          server in the background. Deleting this app's data clears local
          records — synced records stay on the server.
        </p>
        <div class="d-flex gap-2 flex-wrap">
          <button class="btn btn-outline-primary btn-sm" data-go="home">Home</button>
          <button class="btn btn-outline-primary btn-sm" data-go="sync">Sync now</button>
        </div>
      </div>
    </div>`;

  root.querySelectorAll('[data-go]').forEach((el) => {
    el.addEventListener('click', () => navigate(el.getAttribute('data-go') ?? 'home'));
  });
}
