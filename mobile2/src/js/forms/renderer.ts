import type { FormField, FormSection, FieldOption } from '../api/types';

function esc(v: unknown): string {
  return String(v ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function fieldId(field: FormField): string {
  return `f_${field.field_key}`;
}

function optionsHtml(options: FieldOption[] | undefined, selected: string | null): string {
  let html = '<option value="">— Select —</option>';
  for (const o of options ?? []) {
    const sel = selected !== null && String(o.option_value) === String(selected) ? ' selected' : '';
    html += `<option value="${esc(o.option_value)}"${sel}>${esc(o.option_label)}</option>`;
  }
  return html;
}

function labelHtml(field: FormField, extraRequired: boolean): string {
  const req = Boolean(field.is_mandatory) || extraRequired;
  return `<label class="form-label" for="${fieldId(field)}">${esc(field.label)}${req ? ' <span class="text-danger">*</span>' : ''}</label>`;
}

function helpHtml(field: FormField): string {
  return field.help_text ? `<div class="form-text">${esc(field.help_text)}</div>` : '';
}

/** Build the inner control HTML for a field. Returns { html, isComposite } */
export function renderControl(field: FormField, scopeLockedLevel?: string | null): { html: string } {
  const id = fieldId(field);
  const placeholder = field.placeholder ? `placeholder="${esc(field.placeholder)}"` : '';
  const name = `answer[${field.field_key}]`;

  switch (field.type) {
    case 'heading':
      return { html: `<h5 class="form-heading mt-4 mb-2">${esc(field.label)}</h5>` };

    case 'textbox':
      return { html: `<input class="form-control" id="${id}" name="${name}" type="text" ${placeholder} autocomplete="off" />` };

    case 'textarea':
      return { html: `<textarea class="form-control" id="${id}" name="${name}" rows="3" ${placeholder}></textarea>` };

    case 'number':
    case 'decimal':
      return { html: `<input class="form-control" id="${id}" name="${name}" type="number" step="any" ${placeholder} inputmode="decimal" />` };

    case 'date':
      return { html: `<input class="form-control" id="${id}" name="${name}" type="date" />` };

    case 'time':
      return { html: `<input class="form-control" id="${id}" name="${name}" type="time" />` };

    case 'dropdown': {
      const selected = field.default_value ? String(field.default_value) : null;
      return { html: `<select class="form-select" id="${id}" name="${name}">${optionsHtml(field.options, selected)}</select>` };
    }

    case 'radio': {
      let html = '';
      for (const o of field.options ?? []) {
        const sel = field.default_value !== null && String(o.option_value) === String(field.default_value) ? ' checked' : '';
        html += `<div class="form-check">
          <input class="form-check-input" type="radio" name="${name}" id="${id}_${esc(o.option_value)}" value="${esc(o.option_value)}"${sel} />
          <label class="form-check-label" for="${id}_${esc(o.option_value)}">${esc(o.option_label)}</label>
        </div>`;
      }
      return { html };
    }

    case 'checkbox': {
      const checked = field.default_value ? ' checked' : '';
      return { html: `<div class="form-check form-switch">
        <input class="form-check-input" type="checkbox" id="${id}" name="${name}" value="1"${checked} />
        <label class="form-check-label" for="${id}">${esc(field.label)}</label>
      </div>` };
    }

    case 'multi_select': {
      let html = '';
      for (const o of field.options ?? []) {
        html += `<div class="form-check">
          <input class="form-check-input" type="checkbox" name="${name}[]" id="${id}_${esc(o.option_value)}" value="${esc(o.option_value)}" />
          <label class="form-check-label" for="${id}_${esc(o.option_value)}">${esc(o.option_label)}</label>
        </div>`;
      }
      return { html };
    }

    case 'master': {
      const selected = field.default_value ? String(field.default_value) : null;
      return { html: `<select class="form-select" id="${id}" name="${name}" data-master="1">${optionsHtml(field.options, selected)}</select>` };
    }

    case 'location_cascade': {
      const levels = (field.settings?.levels as string[] | undefined) ?? ['district', 'block', 'panchayat', 'village'];
      const levelLabels: Record<string, string> = {
        district: 'District', block: 'Block', panchayat: 'Panchayat', village: 'Village',
      };
      let html = '';
      for (const level of levels) {
        const locked = scopeLockedLevel === level ? ' data-locked="1" disabled' : '';
        html += `<div class="mb-2">
          <label class="form-label small text-muted mb-1">${levelLabels[level] ?? level}</label>
          <select class="form-select" data-cascade-field="${esc(field.field_key)}" data-cascade-level="${level}" id="${id}_${level}" name="${name}[${level}]"${locked}>
            <option value="">— Select —</option>
          </select>
        </div>`;
      }
      return { html };
    }

    case 'gps':
      return { html: `
        <div class="input-group">
          <input type="hidden" data-gps-value="${esc(field.field_key)}" name="${name}" />
          <input type="text" class="form-control" readonly placeholder="Not captured yet" data-gps-display="${esc(field.field_key)}" />
          <button type="button" class="btn btn-outline-primary" data-action="gps" data-field="${esc(field.field_key)}">Capture GPS</button>
        </div>` };

    case 'camera':
    case 'signature':
    case 'barcode':
    case 'qr_code':
    case 'file_upload': {
      const labels: Record<string, { btn: string; title: string }> = {
        camera: { btn: 'Take Photo', title: 'Photo' },
        signature: { btn: 'Sign', title: 'Signature' },
        barcode: { btn: 'Scan Barcode', title: 'Barcode' },
        qr_code: { btn: 'Scan QR', title: 'QR Code' },
        file_upload: { btn: 'Choose File', title: 'File' },
      };
      const l = labels[field.type] ?? { btn: 'Attach', title: 'Attachment' };
      return { html: `
        <div class="media-field">
          <input type="hidden" name="${name}" data-media-value="${esc(field.field_key)}" />
          <div class="d-flex align-items-center gap-2">
            <button type="button" class="btn btn-outline-primary" data-action="${esc(field.type)}" data-field="${esc(field.field_key)}">${l.btn}</button>
            <span class="small text-muted" data-media-name="${esc(field.field_key)}">No ${l.title.toLowerCase()} yet</span>
          </div>
          <div class="mt-2" data-media-preview="${esc(field.field_key)}"></div>
        </div>` };
    }

    case 'auto_number':
      return { html: `<input class="form-control" id="${id}" name="${name}" type="text" readonly data-auto-number="1" />` };

    default:
      return { html: `<input class="form-control" id="${id}" name="${name}" type="text" ${placeholder} />` };
  }
}

/** Full field markup (label + control + help). */
export function renderField(field: FormField, scopeLockedLevel?: string | null): string {
  if (field.type === 'heading' || field.type === 'checkbox') {
    // heading/checkbox render their own label
    const { html } = renderControl(field, scopeLockedLevel);
    const help = helpHtml(field);
    return `<div class="mb-3" data-field="${esc(field.field_key)}" data-type="${esc(field.type)}">${html}${help}</div>`;
  }
  const { html } = renderControl(field, scopeLockedLevel);
  return `<div class="mb-3" data-field="${esc(field.field_key)}" data-type="${esc(field.type)}">
    ${labelHtml(field, false)}
    ${html}
    <div class="invalid-feedback" data-error="${esc(field.field_key)}"></div>
    ${helpHtml(field)}
  </div>`;
}

/** All sections rendered into one HTML blob. */
export function renderSections(sections: FormSection[], scopeLockedLevel?: string | null): string {
  return sections
    .map((s, i) => `
      <div class="card mb-3 survey-section" data-section="${esc(s.id)}">
        <div class="card-header bg-light fw-semibold">${i + 1}. ${esc(s.title ?? 'Section')}</div>
        <div class="card-body">${s.fields.map((f) => renderField(f, scopeLockedLevel)).join('')}</div>
      </div>`)
    .join('');
}
