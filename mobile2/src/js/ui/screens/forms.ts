import { cachedForms } from '../../download';
import { navigate } from '../router';

export async function renderForms(root: HTMLElement): Promise<void> {
  root.innerHTML = '<p class="text-muted">Loading…</p>';
  const forms = await cachedForms();

  if (!forms.length) {
    root.innerHTML = `
      <div class="alert alert-info">
        No forms are assigned to your account yet. Check back later or contact your administrator.
      </div>`;
    return;
  }

  root.innerHTML = `
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

  root.querySelectorAll('[data-open]').forEach((el) => {
    el.addEventListener('click', () => navigate(`form/${el.getAttribute('data-open')}`));
  });
}
