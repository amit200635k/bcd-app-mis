import { cachedForms, downloadAll } from '../../download';
import { isConnected } from '../../native/network';
import { navigate } from '../router';
import { ApiError } from '../../api/client';

export async function renderForms(root: HTMLElement): Promise<void> {
  root.innerHTML = '<p class="text-muted">Loading…</p>';
  const forms = await cachedForms();
  render(root, forms, false);
}

function render(root: HTMLElement, forms: Awaited<ReturnType<typeof cachedForms>>, refreshing: boolean): void {
  if (!forms.length) {
    root.innerHTML = `
      <div class="d-flex align-items-center justify-content-between mb-3">
        <span class="text-muted">No forms assigned yet.</span>
        <button class="btn btn-sm btn-outline-secondary" id="btn-refresh-forms">Refresh</button>
      </div>
      <div class="alert alert-info">
        No forms are assigned to your account yet. Check back later or contact your administrator.
      </div>`;
    bind(root);
    return;
  }

  root.innerHTML = `
    <div class="d-flex align-items-center justify-content-between mb-3">
      <span class="text-muted small">${refreshing ? 'Refreshing…' : `${forms.length} survey${forms.length === 1 ? '' : 's'} available`}</span>
      <button class="btn btn-sm btn-outline-secondary" id="btn-refresh-forms">
        <span id="refresh-spinner" class="spinner-border spinner-border-sm me-1 d-none"></span>
        Refresh
      </button>
    </div>
    <div class="row g-3">
      ${forms
        .map(
          (f) => `
        <div class="col-12 col-md-6">
          <div class="card h-100 shadow-sm">
            <div class="card-body">
              <h5 class="card-title mb-1">${f.title}</h5>
              <div class="text-muted small mb-2">${f.code} · v${f.current_version ?? f.version ?? '?'}</div>
              ${f.description ? `<p class="card-text small">${f.description}</p>` : ''}
              <button class="btn btn-sm btn-primary mt-2" data-open="${f.id}">Fill survey</button>
            </div>
          </div>
        </div>`,
        )
        .join('')}
    </div>`;
  bind(root);
}

function bind(root: HTMLElement): void {
  root.querySelectorAll('[data-open]').forEach((el) => {
    el.addEventListener('click', () => navigate(`form/${el.getAttribute('data-open')}`));
  });

  const btn = root.querySelector<HTMLButtonElement>('#btn-refresh-forms');
  if (btn) {
    btn.addEventListener('click', async () => {
      if (!(await isConnected())) {
        showToast(root, 'No internet connection. Forms not refreshed.');
        return;
      }
      const spinner = root.querySelector('#refresh-spinner');
      btn.disabled = true;
      if (spinner) spinner.classList.remove('d-none');
      try {
        await downloadAll(true);
        const forms = await cachedForms();
        render(root, forms, true);
      } catch (e) {
        const msg = e instanceof ApiError ? e.message : e instanceof Error ? e.message : String(e);
        showToast(root, `Refresh failed: ${msg}`);
        render(root, await cachedForms(), false);
      }
    });
  }
}

function showToast(root: HTMLElement, message: string): void {
  const existing = root.querySelector('.refresh-toast');
  if (existing) existing.remove();
  const el = document.createElement('div');
  el.className = 'alert alert-warning alert-dismissible mt-2 refresh-toast';
  el.innerHTML = `${message}<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>`;
  root.prepend(el);
}
