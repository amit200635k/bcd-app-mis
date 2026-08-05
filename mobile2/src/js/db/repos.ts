import { query, run, exec, beginTransaction, endTransaction } from './index';

/* ---------------------------------------------------------------------------
 * users
 * ------------------------------------------------------------------------ */

export interface LocalUser {
  id: number;
  username: string;
  full_name?: string | null;
  role?: string | null;
  scope_json?: string | null;
  profile_json?: string | null;
  updated_at?: string | null;
}

export async function saveUser(user: LocalUser): Promise<void> {
  await run(
    `INSERT OR REPLACE INTO users (id, username, full_name, role, scope_json, profile_json, updated_at)
     VALUES (?, ?, ?, ?, ?, ?, ?)`,
    [
      user.id,
      user.username,
      user.full_name ?? null,
      user.role ?? null,
      user.scope_json ?? null,
      user.profile_json ?? null,
      user.updated_at ?? new Date().toISOString(),
    ],
  );
}

export async function getUser(): Promise<LocalUser | null> {
  const rows = await query<LocalUser>('SELECT * FROM users ORDER BY id DESC LIMIT 1');
  return rows[0] ?? null;
}

export async function clearUsers(): Promise<void> {
  await exec('DELETE FROM users');
}

/* ---------------------------------------------------------------------------
 * app_settings
 * ------------------------------------------------------------------------ */

export async function setSetting(key: string, value: string): Promise<void> {
  await run(
    `INSERT OR REPLACE INTO app_settings (key, value, updated_at) VALUES (?, ?, ?)`,
    [key, value, new Date().toISOString()],
  );
}

export async function getSetting(key: string): Promise<string | null> {
  const rows = await query<{ value: string | null }>(
    'SELECT value FROM app_settings WHERE key = ?',
    [key],
  );
  return rows[0]?.value ?? null;
}

/* ---------------------------------------------------------------------------
 * forms + form_fields (denormalised definition, versioned via updated_at)
 * ------------------------------------------------------------------------ */

export interface LocalForm {
  id: number;
  code: string;
  title: string;
  description?: string | null;
  current_version?: number | null;
  version?: number | null;
  updated_at?: string | null;
}

export interface LocalField {
  id: number;
  form_id: number;
  section_id: number;
  section_title?: string | null;
  field_key: string;
  label: string;
  type: string;
  is_mandatory: number;
  placeholder?: string | null;
  default_value?: string | null;
  help_text?: string | null;
  allow_multiple: number;
  sort_order: number;
  options?: { option_label: string; option_value: string }[];
  validations?: { rule: string; rule_value?: string | null; error_message?: string | null }[];
  conditions?: {
    target_field_id?: number | null;
    target_field_key?: string | null;
    operator: string;
    condition_value?: string | null;
    action: string;
  }[];
  settings?: Record<string, unknown> | null;
  master_group_id?: number | null;
  master_group_name?: string | null;
}

export async function replaceForms(forms: LocalForm[]): Promise<void> {
  await exec('DELETE FROM forms');
  for (const f of forms) {
    await run(
      `INSERT INTO forms (id, code, title, description, current_version, version, updated_at)
       VALUES (?, ?, ?, ?, ?, ?, ?)`,
      [f.id, f.code, f.title, f.description ?? null, f.current_version ?? null, f.version ?? null, f.updated_at ?? null],
    );
  }
}

export async function getForms(): Promise<LocalForm[]> {
  return query<LocalForm>('SELECT * FROM forms ORDER BY title');
}

export async function getForm(id: number): Promise<LocalForm | null> {
  const rows = await query<LocalForm>('SELECT * FROM forms WHERE id = ?', [id]);
  return rows[0] ?? null;
}

export async function getFormByCode(code: string): Promise<LocalForm | null> {
  const rows = await query<LocalForm>('SELECT * FROM forms WHERE code = ?', [code]);
  return rows[0] ?? null;
}

/** Store one form definition (sections[].fields[]) and return stored count. */
export async function saveFormDefinition(formId: number, sections: unknown[]): Promise<number> {
  await exec(`DELETE FROM form_fields WHERE form_id = ${formId}`);
  let n = 0;
  for (const section of sections as Array<{ id?: number; title?: string; fields?: unknown[] }>) {
    const sectionId = section.id ?? 0;
    const sectionTitle = section.title ?? 'Untitled section';
    for (const f of (section.fields ?? []) as Array<Record<string, unknown>>) {
      const key = String(f.field_key ?? `field_${n + 1}`);
      await run(
        `INSERT INTO form_fields
           (id, form_id, section_id, section_title, field_key, label, type, is_mandatory,
            placeholder, default_value, help_text, allow_multiple, sort_order,
            options_json, validations_json, conditions_json, settings_json)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
        [
          f.id ?? 0,
          formId,
          sectionId,
          sectionTitle,
          key,
          String(f.label ?? key),
          String(f.type ?? 'textbox'),
          f.is_mandatory ? 1 : 0,
          f.placeholder ? String(f.placeholder) : null,
          f.default_value ? String(f.default_value) : null,
          f.help_text ? String(f.help_text) : null,
          f.allow_multiple ? 1 : 0,
          Number(f.sort_order ?? 0),
          JSON.stringify(f.options ?? []),
          JSON.stringify(f.validations ?? []),
          JSON.stringify(f.conditions ?? []),
          f.settings ? JSON.stringify(f.settings) : null,
        ],
      );
      n++;
    }
  }
  return n;
}

/** All fields of a form grouped by section (fill order = stored sort order). */
export async function getFormSections(formId: number): Promise<Array<{ section_id: number; section_title: string; fields: LocalField[] }>> {
  const rows = await query<
    LocalField & {
      section_title: string;
      options_json?: string | null;
      validations_json?: string | null;
      conditions_json?: string | null;
      settings_json?: string | null;
    }
  >('SELECT * FROM form_fields WHERE form_id = ? ORDER BY section_id, sort_order, id', [formId]);
  const sections: Array<{ section_id: number; section_title: string; fields: LocalField[] }> = [];
  for (const row of rows) {
    const f: LocalField = {
      ...row,
      options: safeParse(row.options_json),
      validations: safeParse(row.validations_json),
      conditions: safeParse(row.conditions_json),
      settings: safeParse(row.settings_json),
    };
    let sec = sections.find((s) => s.section_id === row.section_id);
    if (!sec) {
      sec = { section_id: row.section_id, section_title: row.section_title, fields: [] };
      sections.push(sec);
    }
    sec.fields.push(f);
  }
  return sections;
}

function safeParse(json: string | null | undefined): any {
  if (!json) return undefined;
  try {
    return JSON.parse(json);
  } catch {
    return undefined;
  }
}

/* ---------------------------------------------------------------------------
 * locations (offline cascade)
 * ------------------------------------------------------------------------ */

export interface LocationItem {
  level: 'district' | 'block' | 'panchayat' | 'village';
  id: number;
  parent_id?: number | null;
  code?: string | null;
  name: string;
  latitude?: number | null;
  longitude?: number | null;
}

export async function replaceLocations(data: {
  districts?: LocationItem[];
  blocks?: LocationItem[];
  panchayats?: LocationItem[];
  villages?: LocationItem[];
}): Promise<void> {
  await exec('DELETE FROM locations');
  const insert = (level: string, rows: LocationItem[] | undefined, parentKey: string) => {
    for (const r of rows ?? []) {
      const row = r as LocationItem & Record<string, unknown>;
      void run(
        `INSERT INTO locations (level, id, parent_id, code, name, latitude, longitude)
         VALUES (?, ?, ?, ?, ?, ?, ?)`,
        [level, r.id, (row[parentKey] as number | null) ?? null, r.code ?? null, r.name, r.latitude ?? null, r.longitude ?? null],
      );
    }
  };
  insert('district', data.districts, 'parent_id');
  insert('block', data.blocks, 'district_id');
  insert('panchayat', data.panchayats, 'block_id');
  insert('village', data.villages, 'panchayat_id');
}

export async function getLocationChildren(level: 'block' | 'panchayat' | 'village', parentId: number): Promise<LocationItem[]> {
  return query<LocationItem>(
    'SELECT * FROM locations WHERE level = ? AND parent_id = ? ORDER BY name',
    [level, parentId],
  );
}

export async function getDistricts(): Promise<LocationItem[]> {
  return query<LocationItem>("SELECT * FROM locations WHERE level = 'district' ORDER BY name");
}

/* ---------------------------------------------------------------------------
 * lookup_master
 * ------------------------------------------------------------------------ */

export interface MasterItem {
  group_id: number;
  group_code?: string | null;
  group_name?: string | null;
  item_id: number;
  item_code?: string | null;
  item_name: string;
  parent_id?: number | null;
}

export async function replaceMasters(data: { groups?: unknown[]; items?: unknown[] }): Promise<void> {
  await exec('DELETE FROM lookup_master');
  const groups = new Map<number, { code?: string; name?: string }>();
  for (const g of (data.groups ?? []) as Array<Record<string, unknown>>) {
    groups.set(Number(g.id), { code: g.code ? String(g.code) : undefined, name: g.name ? String(g.name) : undefined });
  }
  for (const it of (data.items ?? []) as Array<Record<string, unknown>>) {
    const groupId = Number(it.group_id);
    const g = groups.get(groupId);
    await run(
      `INSERT INTO lookup_master (group_id, group_code, group_name, item_id, item_code, item_name, parent_id)
       VALUES (?, ?, ?, ?, ?, ?, ?)`,
      [groupId, g?.code ?? null, g?.name ?? null, Number(it.id), it.code ? String(it.code) : null, String(it.name ?? ''), it.parent_id ? Number(it.parent_id) : null],
    );
  }
}

export async function getMasterItems(groupId?: number | null): Promise<MasterItem[]> {
  if (!groupId) return [];
  return query<MasterItem>(
    'SELECT * FROM lookup_master WHERE group_id = ? ORDER BY item_name',
    [groupId],
  );
}

/* ---------------------------------------------------------------------------
 * notifications
 * ------------------------------------------------------------------------ */

export interface LocalNotification {
  id: number;
  title: string;
  body?: string | null;
  type?: string | null;
  is_read: number;
  created_at?: string | null;
}

export async function replaceNotifications(rows: LocalNotification[]): Promise<void> {
  await exec('DELETE FROM notifications');
  for (const n of rows) {
    await run(
      `INSERT INTO notifications (id, title, body, type, is_read, created_at)
       VALUES (?, ?, ?, ?, ?, ?)`,
      [n.id, n.title, n.body ?? null, n.type ?? null, n.is_read ? 1 : 0, n.created_at ?? null],
    );
  }
}

export async function getNotifications(): Promise<LocalNotification[]> {
  return query<LocalNotification>('SELECT * FROM notifications ORDER BY created_at DESC, id DESC');
}

export async function getUnreadCount(): Promise<number> {
  const rows = await query<{ c: number }>('SELECT COUNT(*) AS c FROM notifications WHERE is_read = 0');
  return Number(rows[0]?.c ?? 0);
}

export async function markNotificationRead(id: number): Promise<void> {
  await run('UPDATE notifications SET is_read = 1 WHERE id = ?', [id]);
}

/* ---------------------------------------------------------------------------
 * survey records (header + answers + gps + attachments)
 * ------------------------------------------------------------------------ */

export interface LocalRecordHeader {
  record_uuid: string;
  form_id: number;
  form_version_id: number;
  form_code?: string | null;
  form_title?: string | null;
  status: string;
  device_id?: string | null;
  server_record_id?: number | null;
  gps_json?: string | null;
  created_at?: string | null;
  updated_at?: string | null;
  synced_at?: string | null;
}

export interface LocalAnswer {
  record_uuid: string;
  field_key: string;
  field_label?: string | null;
  field_type?: string | null;
  value_text?: string | null;
  value_number?: number | null;
  value_date?: string | null;
  value_json?: string | null;
}

export interface LocalAttachment {
  id?: number;
  record_uuid: string;
  field_key: string;
  category: string;
  local_uri?: string | null;
  file_name?: string | null;
  mime_type?: string | null;
  size_bytes?: number | null;
  upload_state: 'pending' | 'uploaded' | 'failed';
  server_image_id?: number | null;
  server_file_path?: string | null;
}

/**
 * Persist (or overwrite) a record: header, answers, gps and attachments.
 * Answers/gps/attachments are replaced per record_uuid (re-save semantics).
 */
export async function saveRecord(
  header: LocalRecordHeader,
  answers: LocalAnswer[],
  gps: { latitude: number | null; longitude: number | null; accuracy: number | null; altitude: number | null; captured_at?: string | null } | null,
  attachments: LocalAttachment[],
): Promise<void> {
  await beginTransaction();
  try {
    await run(
      `INSERT OR REPLACE INTO survey_header
         (record_uuid, form_id, form_version_id, form_code, form_title, status, device_id, server_record_id, gps_json, created_at, updated_at, synced_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
      [
        header.record_uuid,
        header.form_id,
        header.form_version_id,
        header.form_code ?? null,
        header.form_title ?? null,
        header.status,
        header.device_id ?? null,
        header.server_record_id ?? null,
        header.gps_json ?? null,
        header.created_at ?? new Date().toISOString(),
        header.updated_at ?? new Date().toISOString(),
        header.synced_at ?? null,
      ],
    );
    await run('DELETE FROM survey_answers WHERE record_uuid = ?', [header.record_uuid]);
    for (const a of answers) {
      await run(
        `INSERT INTO survey_answers (record_uuid, field_key, field_label, field_type, value_text, value_number, value_date, value_json)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)`,
        [
          header.record_uuid,
          a.field_key,
          a.field_label ?? null,
          a.field_type ?? null,
          a.value_text ?? null,
          a.value_number ?? null,
          a.value_date ?? null,
          a.value_json ?? null,
        ],
      );
    }
    await run('DELETE FROM gps_logs WHERE record_uuid = ?', [header.record_uuid]);
    if (gps) {
      await run(
        `INSERT INTO gps_logs (record_uuid, latitude, longitude, accuracy, altitude, captured_at)
         VALUES (?, ?, ?, ?, ?, ?)`,
        [header.record_uuid, gps.latitude, gps.longitude, gps.accuracy, gps.altitude, gps.captured_at ?? new Date().toISOString()],
      );
    }
    await run('DELETE FROM attachments WHERE record_uuid = ?', [header.record_uuid]);
    for (const at of attachments) {
      await addAttachment(at);
    }
    await endTransaction(true);
  } catch (e) {
    await endTransaction(false);
    throw e;
  }
}

export async function addAttachment(at: LocalAttachment): Promise<void> {
  await run(
    `INSERT INTO attachments (record_uuid, field_key, category, local_uri, file_name, mime_type, size_bytes, upload_state, server_image_id, server_file_path)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
    [
      at.record_uuid,
      at.field_key,
      at.category,
      at.local_uri ?? null,
      at.file_name ?? null,
      at.mime_type ?? null,
      at.size_bytes ?? null,
      at.upload_state,
      at.server_image_id ?? null,
      at.server_file_path ?? null,
    ],
  );
}

export async function getRecords(formId?: number | null): Promise<LocalRecordHeader[]> {
  if (formId) {
    return query<LocalRecordHeader>('SELECT * FROM survey_header WHERE form_id = ? ORDER BY updated_at DESC', [formId]);
  }
  return query<LocalRecordHeader>('SELECT * FROM survey_header ORDER BY updated_at DESC');
}

export async function getRecord(uuid: string): Promise<LocalRecordHeader | null> {
  const rows = await query<LocalRecordHeader>('SELECT * FROM survey_header WHERE record_uuid = ?', [uuid]);
  return rows[0] ?? null;
}

export async function getRecordAnswers(uuid: string): Promise<LocalAnswer[]> {
  return query<LocalAnswer>('SELECT * FROM survey_answers WHERE record_uuid = ?', [uuid]);
}

export async function getRecordAttachments(uuid: string): Promise<LocalAttachment[]> {
  return query<LocalAttachment>('SELECT * FROM attachments WHERE record_uuid = ?', [uuid]);
}

export async function getRecordGps(uuid: string): Promise<{ latitude: number | null; longitude: number | null; accuracy: number | null; altitude: number | null } | null> {
  const rows = await query<{ latitude: number | null; longitude: number | null; accuracy: number | null; altitude: number | null }>(
    'SELECT latitude, longitude, accuracy, altitude FROM gps_logs WHERE record_uuid = ? ORDER BY id DESC LIMIT 1',
    [uuid],
  );
  return rows[0] ?? null;
}

export async function updateRecordStatus(uuid: string, status: string, syncedAt?: string | null): Promise<void> {
  await run('UPDATE survey_header SET status = ?, synced_at = ? WHERE record_uuid = ?', [
    status,
    syncedAt ?? null,
    uuid,
  ]);
}

export async function updateServerRecordId(uuid: string, serverRecordId: number): Promise<void> {
  await run('UPDATE survey_header SET server_record_id = ? WHERE record_uuid = ?', [serverRecordId, uuid]);
}

export async function getAttachment(id: number): Promise<LocalAttachment | null> {
  const rows = await query<LocalAttachment>('SELECT * FROM attachments WHERE id = ?', [id]);
  return rows[0] ?? null;
}

export async function getPendingAttachments(): Promise<LocalAttachment[]> {
  return query<LocalAttachment>("SELECT * FROM attachments WHERE upload_state = 'pending' ORDER BY id");
}

export async function updateAttachmentUploaded(id: number, serverImageId: number, serverFilePath: string): Promise<void> {
  await run(
    "UPDATE attachments SET upload_state = 'uploaded', server_image_id = ?, server_file_path = ? WHERE id = ?",
    [serverImageId, serverFilePath, id],
  );
}

export async function deleteRecord(uuid: string): Promise<void> {
  await beginTransaction();
  try {
    await run('DELETE FROM survey_header WHERE record_uuid = ?', [uuid]);
    await run('DELETE FROM survey_answers WHERE record_uuid = ?', [uuid]);
    await run('DELETE FROM gps_logs WHERE record_uuid = ?', [uuid]);
    await run('DELETE FROM attachments WHERE record_uuid = ?', [uuid]);
    await run('DELETE FROM sync_queue WHERE record_uuid = ?', [uuid]);
    await endTransaction(true);
  } catch (e) {
    await endTransaction(false);
    throw e;
  }
}

/* ---------------------------------------------------------------------------
 * sync_queue
 * ------------------------------------------------------------------------ */

export interface QueueItem {
  id: number;
  record_uuid: string;
  action: 'upsert' | 'upload_attachment';
  payload_json?: string | null;
  status: 'pending' | 'syncing' | 'synced' | 'failed';
  retry_count: number;
  next_retry_at?: string | null;
  error?: string | null;
  created_at?: string | null;
}

export async function enqueueSync(recordUuid: string, action: 'upsert' | 'upload_attachment', payload: unknown): Promise<void> {
  await run(
    `INSERT INTO sync_queue (record_uuid, action, payload_json, status, retry_count, created_at)
     VALUES (?, ?, ?, 'pending', 0, ?)`,
    [recordUuid, action, JSON.stringify(payload), new Date().toISOString()],
  );
}

export async function getPendingQueue(): Promise<QueueItem[]> {
  return query<QueueItem>(
    `SELECT * FROM sync_queue
     WHERE status = 'pending' AND (next_retry_at IS NULL OR next_retry_at <= ?)
     ORDER BY id`,
    [new Date().toISOString()],
  );
}

export async function getQueueStats(): Promise<{ pending: number; synced: number; failed: number }> {
  const rows = await query<{ status: string; c: number }>(
    "SELECT status, COUNT(*) AS c FROM sync_queue GROUP BY status",
  );
  const stats = { pending: 0, synced: 0, failed: 0 };
  for (const r of rows) {
    if (r.status === 'pending') stats.pending = Number(r.c);
    else if (r.status === 'synced') stats.synced = Number(r.c);
    else if (r.status === 'failed') stats.failed = Number(r.c);
  }
  return stats;
}

export async function getAllQueue(): Promise<QueueItem[]> {
  return query<QueueItem>('SELECT * FROM sync_queue ORDER BY id DESC LIMIT 200');
}

export async function setQueueStatus(id: number, status: QueueItem['status'], error?: string | null, retryAt?: string | null): Promise<void> {
  await run(
    `UPDATE sync_queue SET status = ?, error = ?, next_retry_at = ? WHERE id = ?`,
    [status, error ?? null, retryAt ?? null, id],
  );
}

export async function setQueueRetrying(id: number, retryCount: number, retryAt: string, error: string): Promise<void> {
  await run(
    "UPDATE sync_queue SET status = 'pending', retry_count = ?, next_retry_at = ?, error = ? WHERE id = ?",
    [retryCount, retryAt, error, id],
  );
}

/* ---------------------------------------------------------------------------
 * api_cache
 * ------------------------------------------------------------------------ */

export async function cacheSet(url: string, payload: unknown): Promise<void> {
  await run(
    `INSERT OR REPLACE INTO api_cache (url, payload_json, fetched_at) VALUES (?, ?, ?)`,
    [url, JSON.stringify(payload), new Date().toISOString()],
  );
}

export async function cacheGet<T>(url: string): Promise<T | null> {
  const rows = await query<{ payload_json: string }>('SELECT payload_json FROM api_cache WHERE url = ?', [url]);
  if (!rows[0]) return null;
  try {
    return JSON.parse(rows[0].payload_json) as T;
  } catch {
    return null;
  }
}

/* ---------------------------------------------------------------------------
 * audit_log
 * ------------------------------------------------------------------------ */

export async function audit(event: string, detail: unknown): Promise<void> {
  await run('INSERT INTO audit_log (event, detail_json, created_at) VALUES (?, ?, ?)', [
    event,
    JSON.stringify(detail),
    new Date().toISOString(),
  ]);
}
