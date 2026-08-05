import { getRecords, getRecordAnswers, getRecordAttachments, deleteRecord } from '../../db/repos';

export async function renderRecords(root: HTMLElement): Promise<void> {
  root.innerHTML = '<p class="text-muted">Loading…</p>';
  const records = await getRecords();

  if (!records.length) {
    root.innerHTML = `
      <div class="alert alert-info">
        No records on this device yet. Start by filling a survey from the home screen.
      </div>`;
    return;
  }

  const badge: Record<string, string> = {
    draft: 'bg-secondary',
    submitted: 'bg-primary',
    block_verified: 'bg-info',
    district_verified: 'bg-success',
    approved: 'bg-success',
    published: 'bg-dark',
    rejected: 'bg-danger',
  };

  root.innerHTML = `
    <div class="list-group shadow-sm" id="records-list">
      ${records
        .map(
          (r) => `
        <div class="list-group-item" data-uuid="${r.record_uuid}">
          <div class="d-flex justify-content-between align-items-center">
            <div class="me-2">
              <div class="fw-semibold">${r.form_title ?? `Form #${r.form_id}`}</div>
              <div class="small text-muted font-monospace">${r.record_uuid.slice(0, 8)}… · ${(r.updated_at ?? '').slice(0, 16).replace('T', ' ')}</div>
            </div>
            <span class="badge ${badge[r.status] ?? 'bg-secondary'}">${r.status}</span>
          </div>
          <div class="collapse mt-2" data-detail="${r.record_uuid}">
            <div class="small text-muted mb-2" data-detail-body="${r.record_uuid}">…</div>
            <div class="d-flex gap-2">
              <button class="btn btn-sm btn-outline-danger" data-delete="${r.record_uuid}">Delete</button>
            </div>
          </div>
        </div>`,
        )
        .join('')}
    </div>`;

  root.querySelectorAll('.list-group-item > .d-flex').forEach((head) => {
    head.addEventListener('click', async () => {
      const uuid = (head.closest<HTMLElement>('.list-group-item')!.getAttribute('data-uuid') ?? '').replace(/"/g, '');
      const detail = root.querySelector<HTMLElement>(`[data-detail="${uuid}"]`);
      if (!detail) return;
      detail.classList.toggle('show');
      if (!detail.classList.contains('show')) return;

      const [answers, attachments] = await Promise.all([
        getRecordAnswers(uuid),
        getRecordAttachments(uuid),
      ]);
      const body = root.querySelector<HTMLElement>(`[data-detail-body="${uuid}"]`);
      if (!body) return;
      body.innerHTML = answers.length
        ? answers
            .map((a) => `<div><strong>${a.field_label ?? a.field_key}:</strong> ${displayValue(a)}</div>`)
            .join('') +
          (attachments.length
            ? `<div class="mt-2 text-muted">${attachments.length} attachment${attachments.length === 1 ? '' : 's'} · ${attachments.filter((a) => a.upload_state === 'uploaded').length} uploaded</div>`
            : '')
        : '<div class="text-muted">No answers saved.</div>';
    });
  });

  root.querySelectorAll('[data-delete]').forEach((el) => {
    el.addEventListener('click', async () => {
      const uuid = el.getAttribute('data-delete') ?? '';
      if (!confirm('Delete this record? This cannot be undone.')) return;
      await deleteRecord(uuid);
      await renderRecords(root);
    });
  });
}

function displayValue(a: { field_type?: string | null; value_text?: string | null; value_number?: number | null; value_date?: string | null; value_json?: string | null }): string {
  if (a.value_json) {
    try {
      const v = JSON.parse(a.value_json);
      if (typeof v === 'object' && v !== null) {
        if ('latitude' in v) return `${v.latitude}, ${v.longitude}`;
        return Object.values(v).filter((x) => x !== null && x !== '').join(' / ');
      }
      return String(v);
    } catch {
      return a.value_json;
    }
  }
  if (a.value_number !== null && a.value_number !== undefined) return String(a.value_number);
  if (a.value_date) return String(a.value_date);
  return a.value_text ?? '';
}
