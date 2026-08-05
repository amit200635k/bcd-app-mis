import type { FormField, FormSection, LocationScope } from '../api/types';
import { renderSections } from './renderer';
import { evaluateConditions, missingRequired } from '../engine/conditions';
import { validateField, AnswerValue } from '../engine/validators';
import { captureGps, GpsFix } from '../native/gps';
import { takePicture, saveSignature, signatureToDataUrl, pickFile, CapturedFile } from '../native/media';
import { BarcodeScanner } from '@capacitor-mlkit/barcode-scanning';
import { getDistricts, getLocationChildren, LocationItem } from '../db/repos';
import {
  saveRecord,
  addAttachment,
  enqueueSync,
  getRecords,
  LocalAttachment,
  LocalRecordHeader,
  LocalAnswer,
} from '../db/repos';
import { getDeviceId } from '../native/device';
import { audit } from '../db/repos';

export interface FormEngineOptions {
  sections: FormSection[];
  container: HTMLElement;
  formTitle: string;
  formCode: string;
  formId: number;
  formVersionId: number;
  scope: LocationScope | null;
  /** Existing draft to resume (answers + attachments). */
  existing?: {
    record_uuid: string;
    answers: LocalAnswer[];
    attachments: LocalAttachment[];
  } | null;
}

const CASCADE_LEVELS = ['district', 'block', 'panchayat', 'village'] as const;
type CascadeLevel = (typeof CASCADE_LEVELS)[number];
type ChildLevel = Exclude<CascadeLevel, 'district'>;

export class FormEngine {
  readonly sections: FormSection[];
  readonly formTitle: string;
  readonly formCode: string;
  readonly formId: number;
  readonly formVersionId: number;
  readonly scope: LocationScope | null;
  readonly container: HTMLElement;

  recordUuid: string;
  answers: Record<string, AnswerValue> = {};
  attachments: LocalAttachment[] = [];
  private errors: Record<string, string[]> = {};
  private cascadeData: Partial<Record<CascadeLevel, LocationItem[]>> = {};
  private cascadeLocked: Partial<Record<CascadeLevel, boolean>> = {};
  private cascadeSelected: Partial<Record<CascadeLevel, number | null>> = {};
  private fieldEls: Map<string, FormField> = new Map();
  private mediaCounts: Map<string, number> = new Map();

  constructor(opts: FormEngineOptions) {
    this.sections = opts.sections;
    this.formTitle = opts.formTitle;
    this.formCode = opts.formCode;
    this.formId = opts.formId;
    this.formVersionId = opts.formVersionId;
    this.scope = opts.scope;
    this.container = opts.container;
    this.recordUuid = opts.existing?.record_uuid ?? cryptoRandomUuid();
    this.attachments = [...(opts.existing?.attachments ?? [])];
  }

  allFields(): FormField[] {
    const out: FormField[] = [];
    for (const s of this.sections) out.push(...s.fields);
    return out;
  }

  /** Render the form, restore any draft answers, wire events. */
  async build(): Promise<void> {
    this.container.innerHTML = renderSections(this.sections, this.lockedScopeLevel());
    for (const field of this.allFields()) {
      this.fieldEls.set(field.field_key, field);
    }
    await this.initCascades();
    this.restoreDraft();
    this.initAutoNumbers();
    this.bindEvents();
    this.applyConditions();
  }

  /* ------------------------------------------------------------------ */
  /* scope + cascades                                                    */
  /* ------------------------------------------------------------------ */

  private lockedScopeLevel(): CascadeLevel | null {
    if (this.scope?.village_id) return 'village';
    if (this.scope?.panchayat_id) return 'panchayat';
    if (this.scope?.block_id) return 'block';
    if (this.scope?.district_id) return 'district';
    return null;
  }

  private cascadeLevels(field: FormField): CascadeLevel[] {
    const configured = (field.settings?.levels as string[] | undefined) ?? [...CASCADE_LEVELS];
    return CASCADE_LEVELS.filter((l) => configured.includes(l));
  }

  private async initCascades(): Promise<void> {
    const districts = await getDistricts();
    this.cascadeData.district = districts;

    for (const field of this.allFields()) {
      if (field.type !== 'location_cascade') continue;
      const levels = this.cascadeLevels(field);
      // cascade selects are keyed by field: re-find by data-cascade-field
      for (const level of levels) {
        const sel = this.container.querySelector<HTMLSelectElement>(
          `select[data-cascade-field="${field.field_key}"][data-cascade-level="${level}"]`,
        );
        if (!sel) continue;
        if (level === 'district') {
          this.populateSelect(sel, districts, this.cascadeSelected.district ?? null);
        }
        // lock the user's fixed scope level (and above it stays populated)
        const lockId = this.scope?.[`${level}_id`];
        if (lockId) {
          this.cascadeLocked[level] = true;
          this.cascadeSelected[level] = lockId;
          sel.value = String(lockId);
          sel.setAttribute('disabled', '');
          sel.dataset.locked = '1';
          await this.loadChildren(level, lockId);
        }
      }
    }
  }

  private async loadChildren(level: CascadeLevel, parentId: number): Promise<void> {
    const childLevel = nextLevel(level);
    if (!childLevel) return;
    const items = await getLocationChildren(childLevel, parentId);
    this.cascadeData[childLevel] = items;
    for (const f of this.allFields()) {
      if (f.type !== 'location_cascade') continue;
      const sel = this.container.querySelector<HTMLSelectElement>(
        `select[data-cascade-field="${f.field_key}"][data-cascade-level="${childLevel}"]`,
      );
      if (!sel) continue;
      this.populateSelect(sel, items, null);
      // auto-select a locked scope value for this level if present
      const lockId = this.scope?.[`${childLevel}_id`];
      if (lockId) {
        sel.value = String(lockId);
        sel.setAttribute('disabled', '');
        sel.dataset.locked = '1';
        await this.loadChildren(childLevel, lockId);
      }
    }
  }

  private populateSelect(sel: HTMLSelectElement, items: LocationItem[], selected: number | null): void {
    sel.innerHTML = '<option value="">— Select —</option>';
    for (const it of items) {
      const selAttr = selected !== null && it.id === selected ? ' selected' : '';
      sel.insertAdjacentHTML('beforeend', `<option value="${it.id}"${selAttr}>${it.name}</option>`);
    }
  }

  /* ------------------------------------------------------------------ */
  /* events                                                              */
  /* ------------------------------------------------------------------ */

  private bindEvents(): void {
    const root = this.container;

    root.addEventListener('input', (e) => {
      const target = e.target as HTMLElement;
      if (target.matches('input, select, textarea')) {
        this.readField(this.fieldFor(target));
        this.applyConditions();
      }
    });

    root.addEventListener('change', (e) => {
      const target = e.target as HTMLElement;
      if (target.matches('select[data-cascade-level]')) {
        void this.onCascadeChange(target as HTMLSelectElement);
        return;
      }
      if (target.matches('input, select, textarea')) {
        this.readField(this.fieldFor(target));
        this.applyConditions();
      }
    });

    root.addEventListener('click', (e) => {
      const btn = (e.target as HTMLElement).closest<HTMLElement>('[data-action]');
      if (!btn) return;
      const action = btn.dataset.action ?? '';
      const fieldKey = btn.dataset.field ?? '';
      void this.onAction(action, fieldKey, btn);
    });
  }

  private fieldFor(el: HTMLElement): FormField | undefined {
    const fieldEl = el.closest<HTMLElement>('[data-field]');
    if (!fieldEl) return undefined;
    return this.fieldEls.get(fieldEl.dataset.field ?? '');
  }

  private async onAction(action: string, fieldKey: string, btn: HTMLElement): Promise<void> {
    switch (action) {
      case 'gps':
        await this.captureGpsField(fieldKey, btn);
        break;
      case 'camera':
        await this.captureMedia(fieldKey, 'camera');
        break;
      case 'signature':
        await this.captureSignature(fieldKey);
        break;
      case 'barcode':
      case 'qr_code':
        await this.scanBarcode(fieldKey);
        break;
      case 'file_upload':
        await this.pickFileField(fieldKey);
        break;
    }
  }

  private async onCascadeChange(sel: HTMLSelectElement): Promise<void> {
    const field = this.fieldFor(sel);
    if (!field) return;
    const level = sel.dataset.cascadeLevel as CascadeLevel;
    const value = sel.value ? Number(sel.value) : null;
    this.cascadeSelected[level] = value;
    this.clearChildren(field, level);
    if (value) {
      await this.loadChildren(level, value);
    }
    this.readField(field);
  }

  private clearChildren(field: FormField, level: CascadeLevel): void {
    const levels = this.cascadeLevels(field);
    const idx = levels.indexOf(level);
    for (let i = idx + 1; i < levels.length; i++) {
      const child = levels[i];
      this.cascadeSelected[child] = null;
      this.cascadeData[child] = [];
      for (const f of this.allFields()) {
        if (f.type !== 'location_cascade') continue;
        const sel = this.container.querySelector<HTMLSelectElement>(
          `select[data-cascade-field="${f.field_key}"][data-cascade-level="${child}"]`,
        );
        if (sel && !sel.dataset.locked) {
          this.populateSelect(sel, [], null);
        }
      }
    }
  }

  /* ------------------------------------------------------------------ */
  /* media fields                                                        */
  /* ------------------------------------------------------------------ */

  private async captureGpsField(fieldKey: string, btn: HTMLElement): Promise<void> {
    const button = btn as HTMLButtonElement;
    button.disabled = true;
    try {
      const fix: GpsFix = await captureGps();
      this.answers[fieldKey] = { ...fix };
      const display = this.container.querySelector<HTMLInputElement>(`[data-gps-display="${fieldKey}"]`);
      if (display) {
        display.value = `${fix.latitude.toFixed(6)}, ${fix.longitude.toFixed(6)} (±${fix.accuracy.toFixed(1)}m)`;
      }
      const hidden = this.container.querySelector<HTMLInputElement>(`[data-gps-value="${fieldKey}"]`);
      if (hidden) hidden.value = JSON.stringify(this.answers[fieldKey]);
      this.applyConditions();
    } catch (e) {
      this.showError(fieldKey, 'GPS capture failed: ' + String(e instanceof Error ? e.message : e));
    } finally {
      button.disabled = false;
    }
  }

  private async captureMedia(fieldKey: string, category: string): Promise<void> {
    try {
      const file: CapturedFile = await takePicture();
      await this.attach(fieldKey, category, file);
    } catch (e) {
      this.showError(fieldKey, String(e instanceof Error ? e.message : e));
    }
  }

  private async captureSignature(fieldKey: string): Promise<void> {
    const { canvas, overlay } = await this.openSignaturePad();
    try {
      const dataUrl = await new Promise<string>((resolve, reject) => {
        const finish = () => resolve(signatureToDataUrl(canvas));
        const cancel = () => reject(new Error('Signature cancelled.'));
        overlay.querySelector('[data-sig-save]')!.addEventListener('click', finish);
        overlay.querySelector('[data-sig-cancel]')!.addEventListener('click', cancel);
        overlay.querySelector('[data-sig-clear]')!.addEventListener('click', () => clearCanvas(canvas));
        overlay.addEventListener('click', (e) => {
          if (e.target === overlay) cancel();
        });
      });
      const file: CapturedFile = await saveSignature(dataUrl);
      await this.attach(fieldKey, 'signature', file);
    } catch (e) {
      // cancelled — silent
    } finally {
      overlay.remove();
    }
  }

  private async scanBarcode(fieldKey: string): Promise<void> {
    try {
      const supported = await BarcodeScanner.isSupported().then((r) => r.supported).catch(() => false);
      if (!supported) {
        this.showError(fieldKey, 'Barcode scanning is not supported on this device.');
        return;
      }
      await BarcodeScanner.requestPermissions();
      const moduleAvailable = await BarcodeScanner.isGoogleBarcodeScannerModuleAvailable()
        .then((r) => r.available)
        .catch(() => false);
      if (!moduleAvailable) {
        try {
          await BarcodeScanner.installGoogleBarcodeScannerModule();
        } catch {
          this.showError(fieldKey, 'Barcode scanner module could not be installed.');
          return;
        }
      }
      const result = await BarcodeScanner.scan();
      const value = result.barcodes?.[0]?.rawValue ?? '';
      this.answers[fieldKey] = value;
      const input = this.container.querySelector<HTMLInputElement>(`input[name="answer[${fieldKey}]"]`);
      if (input) input.value = value;
      this.applyConditions();
    } catch (e) {
      this.showError(fieldKey, 'Scan failed: ' + String(e instanceof Error ? e.message : e));
    }
  }

  private async pickFileField(fieldKey: string): Promise<void> {
    try {
      const file: CapturedFile = await pickFile();
      await this.attach(fieldKey, 'file', file);
    } catch (e) {
      this.showError(fieldKey, String(e instanceof Error ? e.message : e));
    }
  }

  /** Record a captured file as a pending attachment + answer ref. */
  private async attach(fieldKey: string, category: string, file: CapturedFile): Promise<void> {
    const count = this.mediaCounts.get(fieldKey) ?? 0;
    this.mediaCounts.set(fieldKey, count + 1);
    const attachment: LocalAttachment = {
      record_uuid: this.recordUuid,
      field_key: fieldKey,
      category,
      local_uri: file.uri,
      file_name: file.fileName,
      mime_type: file.mimeType,
      size_bytes: file.sizeBytes,
      upload_state: 'pending',
    };
    this.attachments.push(attachment);
    await addAttachment(attachment);

    // answer carries a local ref (empty for server submit until upload links it)
    this.answers[fieldKey] = JSON.stringify({
      local_attachment: attachment.local_uri,
      file_name: attachment.file_name,
      category,
    });
    const hidden = this.container.querySelector<HTMLInputElement>(`[data-media-value="${fieldKey}"]`);
    if (hidden) hidden.value = String(this.answers[fieldKey]);

    const nameEl = this.container.querySelector<HTMLElement>(`[data-media-name="${fieldKey}"]`);
    if (nameEl) nameEl.textContent = `${attachment.file_name} (${this.attachments.filter((a) => a.field_key === fieldKey).length})`;

    const preview = this.container.querySelector<HTMLElement>(`[data-media-preview="${fieldKey}"]`);
    if (preview && (category === 'camera' || category === 'signature')) {
      const img = document.createElement('img');
      img.src = file.dataUrl;
      img.className = 'img-fluid rounded border';
      img.style.maxHeight = '140px';
      preview.innerHTML = '';
      preview.appendChild(img);
    }
  }

  /* ------------------------------------------------------------------ */
  /* auto numbers + draft restore                                        */
  /* ------------------------------------------------------------------ */

  private async initAutoNumbers(): Promise<void> {
    const existing = await getRecords(this.formId);
    let seq = existing.length + 1;
    for (const field of this.allFields()) {
      if (field.type !== 'auto_number') continue;
      const key = field.field_key;
      if (this.answers[key]) continue;
      const value = `${this.formCode}-${String(seq).padStart(5, '0')}`;
      seq++;
      this.answers[key] = value;
      const input = this.container.querySelector<HTMLInputElement>(`[data-auto-number]`);
      if (input) input.value = value;
    }
  }

  private restoreDraft(): void {
    const existing = this.existingAnswers;
    if (!existing) return;
    for (const field of this.allFields()) {
      const key = field.field_key;
      const row = existing.find((a) => a.field_key === key);
      if (!row) continue;
      const value = deserializeAnswer(row);
      if (value === null && !row.value_text && !row.value_json) continue;
      this.answers[key] = value;
      this.writeField(field, value);
    }
    for (const at of this.attachments) {
      const preview = this.container.querySelector<HTMLElement>(`[data-media-preview="${at.field_key}"]`);
      const nameEl = this.container.querySelector<HTMLElement>(`[data-media-name="${at.field_key}"]`);
      if (nameEl) nameEl.textContent = at.file_name ?? 'attached';
      if (preview) {
        const img = document.createElement('img');
        img.className = 'img-fluid rounded border';
        img.style.maxHeight = '140px';
        img.src = `data:${at.mime_type ?? 'image/jpeg'};base64,`; // placeholder until cached read
        preview.appendChild(img);
      }
    }
  }

  private get existingAnswers(): LocalAnswer[] | null {
    // stored on the instance by the screen before build
    return this._existingAnswers;
  }

  private _existingAnswers: LocalAnswer[] | null = null;
  setExistingAnswers(rows: LocalAnswer[]): void {
    this._existingAnswers = rows;
  }

  private writeField(field: FormField, value: AnswerValue): void {
    const root = this.container;
    const key = field.field_key;
    switch (field.type) {
      case 'textbox':
      case 'textarea':
      case 'number':
      case 'decimal':
      case 'date':
      case 'time':
      case 'auto_number': {
        const el = root.querySelector<HTMLInputElement>(`[name="answer[${key}]"]`);
        if (el) el.value = String(value ?? '');
        break;
      }
      case 'dropdown':
      case 'master': {
        const el = root.querySelector<HTMLSelectElement>(`[name="answer[${key}]"]`);
        const v = value !== null && typeof value === 'object'
          ? String((value as Record<string, unknown>).master_id ?? '')
          : String(value ?? '');
        if (el) el.value = v;
        break;
      }
      case 'radio': {
        const el = root.querySelector<HTMLInputElement>(`input[name="answer[${key}]"][value="${String(value ?? '')}"]`);
        if (el) el.checked = true;
        break;
      }
      case 'checkbox':
      case 'multi_select': {
        const values = Array.isArray(value) ? value.map(String) : value !== null && value !== undefined ? [String(value)] : [];
        root.querySelectorAll<HTMLInputElement>(`input[name="answer[${key}][]"]`).forEach((el) => {
          el.checked = values.includes(el.value);
        });
        break;
      }
      case 'gps': {
        const display = root.querySelector<HTMLInputElement>(`[data-gps-display="${key}"]`);
        if (display && value && typeof value === 'object') {
          const v = value as { latitude: number; longitude: number; accuracy: number };
          display.value = `${v.latitude.toFixed(6)}, ${v.longitude.toFixed(6)} (±${v.accuracy.toFixed(1)}m)`;
        }
        break;
      }
      case 'location_cascade': {
        const v = value as Record<string, unknown> | null;
        if (v) {
          for (const level of CASCADE_LEVELS) {
            const id = v[`${level}_id`];
            const sel = root.querySelector<HTMLSelectElement>(
              `select[data-cascade-field="${key}"][data-cascade-level="${level}"]`,
            );
            if (sel && id) sel.value = String(id);
          }
        }
        break;
      }
      case 'barcode':
      case 'qr_code': {
        const el = root.querySelector<HTMLInputElement>(`input[name="answer[${key}]"]`);
        if (el) el.value = String(value ?? '');
        break;
      }
      default:
        break;
    }
  }

  /* ------------------------------------------------------------------ */
  /* answer reading + serialization                                      */
  /* ------------------------------------------------------------------ */

  private readField(field: FormField | undefined): void {
    if (!field) return;
    this.answers[field.field_key] = this.readValue(field);
  }

  private readValue(field: FormField): AnswerValue {
    const root = this.container;
    const key = field.field_key;
    switch (field.type) {
      case 'textbox':
      case 'textarea':
      case 'number':
      case 'decimal':
      case 'date':
      case 'time': {
        const el = root.querySelector<HTMLInputElement>(`[name="answer[${key}]"]`);
        return el?.value ?? null;
      }
      case 'dropdown':
      case 'master': {
        const el = root.querySelector<HTMLSelectElement>(`[name="answer[${key}]"]`);
        const v = el?.value ?? '';
        if (field.type === 'master') {
          const opt = field.options?.find((o) => o.option_value === v);
          return v ? { master_id: Number(v), name: opt?.option_label ?? v } : null;
        }
        return v || null;
      }
      case 'radio': {
        const el = root.querySelector<HTMLInputElement>(`input[name="answer[${key}]"]:checked`);
        return el?.value ?? null;
      }
      case 'checkbox': {
        const el = root.querySelector<HTMLInputElement>(`input[name="answer[${key}]"]`);
        return el?.checked ? '1' : null;
      }
      case 'multi_select': {
        const els = [...root.querySelectorAll<HTMLInputElement>(`input[name="answer[${key}][]"]:checked`)];
        return els.map((el) => el.value);
      }
      case 'location_cascade': {
        const levels = this.cascadeLevels(field);
        const out: Record<string, unknown> = {};
        for (const level of levels) {
          const sel = root.querySelector<HTMLSelectElement>(
            `select[data-cascade-field="${key}"][data-cascade-level="${level}"]`,
          );
          const id = sel?.value ? Number(sel.value) : 0;
          const name = sel?.selectedOptions?.[0]?.text ?? '';
          if (id > 0 || name) {
            out[`${level}_id`] = id > 0 ? id : null;
            out[`${level}_name`] = name !== '— Select —' ? name : null;
          }
        }
        return Object.keys(out).length ? out : null;
      }
      case 'gps': {
        const el = root.querySelector<HTMLInputElement>(`[data-gps-value="${key}"]`);
        if (!el?.value) return null;
        try {
          return JSON.parse(el.value);
        } catch {
          return null;
        }
      }
      case 'camera':
      case 'signature':
      case 'file_upload': {
        const el = root.querySelector<HTMLInputElement>(`[data-media-value="${key}"]`);
        return el?.value || null;
      }
      case 'barcode':
      case 'qr_code': {
        const el = root.querySelector<HTMLInputElement>(`[name="answer[${key}]"]`);
        return el?.value || null;
      }
      case 'auto_number': {
        const el = root.querySelector<HTMLInputElement>(`[name="answer[${key}]"]`);
        return el?.value || this.answers[key] || null;
      }
      default:
        return null;
    }
  }

  /** Serialise current answers into rows matching the server's answer columns. */
  serializeAnswers(): LocalAnswer[] {
    this.readAll();
    const rows: LocalAnswer[] = [];
    for (const field of this.allFields()) {
      if (field.type === 'heading') continue;
      const value = this.answers[field.field_key];
      const row = serializeValue(field, value);
      if (row) {
        rows.push({
          record_uuid: this.recordUuid,
          field_key: field.field_key,
          field_label: field.label,
          field_type: field.type,
          ...row,
        });
      }
    }
    return rows;
  }

  private readAll(): void {
    for (const field of this.allFields()) {
      this.answers[field.field_key] = this.readValue(field);
    }
  }

  /* ------------------------------------------------------------------ */
  /* conditions + validation                                             */
  /* ------------------------------------------------------------------ */

  applyConditions(): void {
    this.readAll();
    const fields = this.allFields();
    const ev = evaluateConditions(fields, this.answers);
    for (const field of fields) {
      const wrapper = this.container.querySelector<HTMLElement>(`[data-field="${field.field_key}"]`);
      if (!wrapper) continue;
      const hidden = ev.visible.get(field.field_key) === false;
      wrapper.classList.toggle('d-none', hidden);
      if (hidden) {
        this.answers[field.field_key] = null; // mirror server: hidden answers dropped
        this.clearDomValue(field);
      }
    }
  }

  private clearDomValue(field: FormField): void {
    const root = this.container;
    const key = field.field_key;
    switch (field.type) {
      case 'textbox':
      case 'textarea':
      case 'number':
      case 'decimal':
      case 'date':
      case 'time':
      case 'barcode':
      case 'qr_code': {
        const el = root.querySelector<HTMLInputElement>(`[name="answer[${key}]"]`);
        if (el) el.value = '';
        break;
      }
      case 'dropdown':
      case 'master': {
        const el = root.querySelector<HTMLSelectElement>(`[name="answer[${key}]"]`);
        if (el) el.value = '';
        break;
      }
      case 'radio': {
        root.querySelectorAll<HTMLInputElement>(`input[name="answer[${key}]"]`).forEach((el) => (el.checked = false));
        break;
      }
      case 'multi_select':
      case 'checkbox': {
        root.querySelectorAll<HTMLInputElement>(`input[name="answer[${key}][]"]`).forEach((el) => (el.checked = false));
        break;
      }
      case 'gps': {
        const d = root.querySelector<HTMLInputElement>(`[data-gps-display="${key}"]`);
        const h = root.querySelector<HTMLInputElement>(`[data-gps-value="${key}"]`);
        if (d) d.value = '';
        if (h) h.value = '';
        break;
      }
      case 'camera':
      case 'signature':
      case 'file_upload': {
        const h = root.querySelector<HTMLInputElement>(`[data-media-value="${key}"]`);
        if (h) h.value = '';
        const n = root.querySelector<HTMLElement>(`[data-media-name="${key}"]`);
        if (n) n.textContent = 'No attachment yet';
        break;
      }
      case 'location_cascade': {
        for (const level of CASCADE_LEVELS) {
          const sel = root.querySelector<HTMLSelectElement>(
            `select[data-cascade-field="${key}"][data-cascade-level="${level}"]`,
          );
          if (sel && !sel.dataset.locked) this.populateSelect(sel, [], null);
        }
        break;
      }
      default:
        break;
    }
  }

  validate(): boolean {
    this.readAll();
    const fields = this.allFields();
    const errors: Record<string, string[]> = {};

    const ev = evaluateConditions(fields, this.answers);
    const missing = missingRequired(fields, this.answers, ev);

    for (const field of fields) {
      const key = field.field_key;
      if (field.type === 'heading') continue;
      if (ev.visible.get(key) === false) continue;
      const list: string[] = [];
      if (missing[key]) list.push(...missing[key]);
      list.push(...validateField(field, this.answers[key] ?? null));
      if (list.length) errors[key] = list;
    }

    this.errors = errors;
    this.renderErrors();
    return Object.keys(errors).length === 0;
  }

  private renderErrors(): void {
    for (const field of this.allFields()) {
      const wrapper = this.container.querySelector<HTMLElement>(`[data-field="${field.field_key}"]`);
      if (!wrapper) continue;
      const errEl = wrapper.querySelector<HTMLElement>(`[data-error="${field.field_key}"]`);
      const list = this.errors[field.field_key] ?? [];
      wrapper.classList.toggle('was-validated', false);
      wrapper.querySelectorAll('.is-invalid').forEach((el) => el.classList.remove('is-invalid'));
      if (list.length && errEl) {
        wrapper.querySelectorAll('input, select, textarea').forEach((el) => el.classList.add('is-invalid'));
        errEl.textContent = list.join(' ');
      }
    }
  }

  private showError(fieldKey: string, message: string): void {
    const wrapper = this.container.querySelector<HTMLElement>(`[data-field="${fieldKey}"]`);
    const errEl = wrapper?.querySelector<HTMLElement>(`[data-error="${fieldKey}"]`);
    if (errEl) {
      errEl.textContent = message;
      wrapper!.querySelectorAll('input, select, textarea').forEach((el) => el.classList.add('is-invalid'));
    }
  }

  /* ------------------------------------------------------------------ */
  /* persistence                                                         */
  /* ------------------------------------------------------------------ */

  private headerFor(status: string): LocalRecordHeader {
    return {
      record_uuid: this.recordUuid,
      form_id: this.formId,
      form_version_id: this.formVersionId,
      form_code: this.formCode,
      form_title: this.formTitle,
      status,
      device_id: null,
      gps_json: null,
    };
  }

  private gpsFor(): { latitude: number | null; longitude: number | null; accuracy: number | null; altitude: number | null; captured_at: string | null } | null {
    for (const field of this.allFields()) {
      if (field.type !== 'gps') continue;
      const v = this.answers[field.field_key];
      if (v && typeof v === 'object') {
        const g = v as unknown as GpsFix;
        return { latitude: g.latitude, longitude: g.longitude, accuracy: g.accuracy, altitude: g.altitude ?? null, captured_at: g.captured_at };
      }
    }
    return null;
  }

  /** Persist locally (status 'draft' skips server validation on upload). */
  async saveDraft(): Promise<void> {
    this.readAll();
    const answers = this.serializeAnswers();
    await saveRecord(this.headerFor('draft'), answers, this.gpsFor(), this.attachments);
    await audit('record.saved_draft', { record_uuid: this.recordUuid, form_id: this.formId });
  }

  /** Validate → persist locally → enqueue sync work. Returns the record uuid. */
  async submit(): Promise<{ ok: boolean; recordUuid: string; errors?: Record<string, string[]> }> {
    this.readAll();
    if (!this.validate()) {
      return { ok: false, recordUuid: this.recordUuid, errors: this.errors };
    }
    await this.saveDraft(); // persists answers + attachments (status overwritten below)
    await saveRecord(this.headerFor('submitted'), this.serializeAnswers(), this.gpsFor(), this.attachments);

    const deviceId = await getDeviceId();
    await enqueueSync(this.recordUuid, 'upsert', {
      form_id: this.formId,
      form_version_id: this.formVersionId,
      status: 'submitted',
      device_id: deviceId,
    });
    for (const at of this.attachments) {
      await enqueueSync(this.recordUuid, 'upload_attachment', {
        attachment_id: at.id ?? 0,
        field_key: at.field_key,
        category: at.category,
      });
    }
    await audit('record.submitted_offline', { record_uuid: this.recordUuid, form_id: this.formId });
    return { ok: true, recordUuid: this.recordUuid };
  }

  /* ------------------------------------------------------------------ */
  /* signature pad                                                       */
  /* ------------------------------------------------------------------ */

  private openSignaturePad(): Promise<{ canvas: HTMLCanvasElement; overlay: HTMLElement }> {
    return new Promise((resolve) => {
      const overlay = document.createElement('div');
      overlay.className = 'sig-overlay';
      overlay.innerHTML = `
        <div class="sig-card">
          <div class="sig-title">Draw your signature</div>
          <canvas class="sig-canvas" width="600" height="220"></canvas>
          <div class="sig-actions">
            <button type="button" class="btn btn-outline-secondary" data-sig-clear>Clear</button>
            <button type="button" class="btn btn-outline-danger" data-sig-cancel>Cancel</button>
            <button type="button" class="btn btn-primary" data-sig-save>Use Signature</button>
          </div>
        </div>`;
      document.body.appendChild(overlay);
      const canvas = overlay.querySelector<HTMLCanvasElement>('canvas')!;
      setupSignatureCanvas(canvas);
      resolve({ canvas, overlay });
    });
  }
}

/* ---------------------------------------------------------------------- */

function nextLevel(level: CascadeLevel): ChildLevel | null {
  const idx = CASCADE_LEVELS.indexOf(level);
  return idx >= 0 && idx < CASCADE_LEVELS.length - 1 ? (CASCADE_LEVELS[idx + 1] as ChildLevel) : null;
}

function cryptoRandomUuid(): string {
  const bytes = new Uint8Array(16);
  if (typeof crypto !== 'undefined' && crypto.getRandomValues) {
    crypto.getRandomValues(bytes);
  } else {
    for (let i = 0; i < 16; i++) bytes[i] = Math.floor(Math.random() * 256);
  }
  bytes[6] = (bytes[6] & 0x0f) | 0x40;
  bytes[8] = (bytes[8] & 0x3f) | 0x80;
  const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0'));
  return `${hex.slice(0, 4).join('')}-${hex.slice(4, 6).join('')}-${hex.slice(6, 8).join('')}-${hex.slice(8, 10).join('')}-${hex.slice(10).join('')}`;
}

/** Answer row (value_text/number/date/json) mirroring RecordService::normalize*. */
function serializeValue(field: FormField, value: AnswerValue): Partial<LocalAnswer> | null {
  if (value === null || value === undefined || value === '') return null;
  if (Array.isArray(value)) {
    return { value_json: JSON.stringify(value) };
  }
  if (typeof value === 'object') {
    return { value_json: JSON.stringify(value) };
  }
  if (field.type === 'number' || field.type === 'decimal') {
    return { value_number: Number(value) };
  }
  if (field.type === 'date') {
    return { value_date: String(value) };
  }
  return { value_text: String(value) };
}

/** Answer row → engine answer value (reverse of serializeValue). */
function deserializeAnswer(row: LocalAnswer): AnswerValue {
  if (row.value_json) {
    try {
      return JSON.parse(row.value_json);
    } catch {
      return row.value_text ?? null;
    }
  }
  if (row.value_number !== null && row.value_number !== undefined) return row.value_number;
  if (row.value_date) return row.value_date;
  return row.value_text ?? null;
}

function clearCanvas(canvas: HTMLCanvasElement): void {
  const ctx = canvas.getContext('2d');
  if (ctx) {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
  }
}

function setupSignatureCanvas(canvas: HTMLCanvasElement): void {
  const ctx = canvas.getContext('2d');
  if (!ctx) return;
  ctx.fillStyle = '#fff';
  ctx.fillRect(0, 0, canvas.width, canvas.height);
  ctx.strokeStyle = '#000';
  ctx.lineWidth = 2;
  ctx.lineCap = 'round';

  let drawing = false;
  const getPos = (e: PointerEvent | MouseEvent | TouchEvent): { x: number; y: number } => {
    const rect = canvas.getBoundingClientRect();
    if ('touches' in e) {
      const t = e.touches[0] ?? (e as TouchEvent).changedTouches[0];
      return { x: t.clientX - rect.left, y: t.clientY - rect.top };
    }
    const p = e as MouseEvent;
    return { x: p.clientX - rect.left, y: p.clientY - rect.top };
  };

  const down = (e: PointerEvent) => {
    drawing = true;
    const p = getPos(e);
    ctx!.beginPath();
    ctx!.moveTo(p.x, p.y);
    canvas.setPointerCapture(e.pointerId);
  };
  const move = (e: PointerEvent) => {
    if (!drawing) return;
    const p = getPos(e);
    ctx!.lineTo(p.x, p.y);
    ctx!.stroke();
  };
  const up = () => {
    drawing = false;
    ctx!.beginPath();
  };

  canvas.addEventListener('pointerdown', down);
  canvas.addEventListener('pointermove', move);
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointerleave', up);
}
