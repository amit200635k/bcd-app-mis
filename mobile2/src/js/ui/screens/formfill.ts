import { cachedForm } from '../../download';
import { getFormSections, getRecordAnswers, getRecordAttachments, getRecords } from '../../db/repos';
import { FormEngine } from '../../forms/formEngine';
import { fetchLocationScope } from '../../download';
import { navigate } from '../router';
import type { RouteParams } from '../router';
import type { FormSection } from '../../api/types';

function mapSections(rows: Array<{ section_id: number; section_title: string; fields: unknown[] }>): FormSection[] {
  return rows.map((s) => ({ id: s.section_id, title: s.section_title, fields: s.fields as never }));
}

export async function renderFormFill(root: HTMLElement, params: RouteParams): Promise<void> {
  const formId = Number(params.id ?? 0);
  if (!formId) {
    navigate('forms');
    return;
  }

  const form = await cachedForm(formId);
  if (!form) {
    root.innerHTML = `<div class="alert alert-warning">This form is not available offline. Pull to refresh from Settings → Sync now.</div>`;
    return;
  }

  const [sections, scope, existingRecords] = await Promise.all([
    getFormSections(formId).then(mapSections),
    fetchLocationScope().catch(() => null),
    getRecords(formId),
  ]);

  if (!sections.length) {
    root.innerHTML = `<div class="alert alert-warning">This form has no fields assigned. Sync to get the latest definition.</div>`;
    return;
  }

  // Resume the most recent draft, if any
  const draft = existingRecords.find((r) => r.status === 'draft') ?? null;
  const existing = draft
    ? {
        record_uuid: draft.record_uuid,
        answers: await getRecordAnswers(draft.record_uuid).catch(() => []),
        attachments: await getRecordAttachments(draft.record_uuid).catch(() => []),
      }
    : null;

  root.innerHTML = `
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h5 class="mb-0">${form.title}</h5>
      <div>
        <button class="btn btn-outline-secondary btn-sm" id="btn-back">Back</button>
      </div>
    </div>
    <div id="form-root"></div>
    <div class="sticky-bottom bg-body pt-2 pb-3">
      <div class="d-flex gap-2">
        <button class="btn btn-outline-primary flex-fill" id="btn-draft" disabled>Save draft</button>
        <button class="btn btn-primary flex-fill" id="btn-submit" disabled>Submit</button>
      </div>
    </div>`;

  const container = root.querySelector<HTMLElement>('#form-root')!;
  const draftBtn = root.querySelector<HTMLButtonElement>('#btn-draft')!;
  const submitBtn = root.querySelector<HTMLButtonElement>('#btn-submit')!;

  root.querySelector('#btn-back')!.addEventListener('click', () => navigate('forms'));

  const engine = new FormEngine({
    sections,
    container,
    formTitle: form.title,
    formCode: form.code,
    formId: form.id,
    formVersionId: form.current_version ?? form.version ?? 1,
    scope,
    existing,
  });
  if (existing) {
    engine.setExistingAnswers(existing.answers);
  }

  try {
    await engine.build();
    draftBtn.disabled = false;
    submitBtn.disabled = false;
  } catch (e) {
    container.innerHTML = `<div class="alert alert-danger">Could not build the form: ${e instanceof Error ? e.message : String(e)}</div>`;
    return;
  }

  draftBtn.addEventListener('click', async () => {
    draftBtn.disabled = true;
    try {
      await engine.saveDraft();
      navigate('records');
    } catch (e) {
      container.insertAdjacentHTML(
        'afterbegin',
        `<div class="alert alert-danger">Save failed: ${e instanceof Error ? e.message : String(e)}</div>`,
      );
      draftBtn.disabled = false;
    }
  });

  submitBtn.addEventListener('click', async () => {
    submitBtn.disabled = true;
    submitBtn.textContent = 'Saving…';
    try {
      const res = await engine.submit();
      if (!res.ok && res.errors) {
        const list = Object.entries(res.errors)
          .map(([k, msgs]) => `${k}: ${msgs.join('; ')}`)
          .join('<br/>');
        container.insertAdjacentHTML('afterbegin', `<div class="alert alert-danger mb-3">${list}</div>`);
        submitBtn.disabled = false;
        submitBtn.textContent = 'Submit';
        return;
      }
      navigate('records');
    } catch (e) {
      container.insertAdjacentHTML(
        'afterbegin',
        `<div class="alert alert-danger">Submit failed: ${e instanceof Error ? e.message : String(e)}</div>`,
      );
      submitBtn.disabled = false;
      submitBtn.textContent = 'Submit';
    }
  });
}
