import { api, ENDPOINTS } from './api/client';
import type { SurveyForm, ServerNotification, LocationScope } from './api/types';
import {
  replaceForms,
  saveFormDefinition,
  replaceLocations,
  replaceMasters,
  replaceNotifications,
  setSetting,
  getSetting,
  getForms,
  getForm,
} from './db/repos';

export interface DownloadResult {
  forms: number;
  fields: number;
  locations: boolean;
  masters: boolean;
  notifications: number;
  refreshed: boolean;
}

/**
 * Download every offline resource (/v1/forms with full definitions,
 * /v1/masters/locations, /v1/masters, /v1/notifications) and persist it in
 * SQLite. Each resource carries an `updated_at` — only changed resources are
 * re-downloaded (versioning per the architecture doc).
 */
export async function downloadAll(force = false): Promise<DownloadResult> {
  const result: DownloadResult = { forms: 0, fields: 0, locations: false, masters: false, notifications: 0, refreshed: false };

  // ---- forms (full definitions, access-filtered by the server) ----
  const serverStamp = await api<{ updated_at: string; forms: SurveyForm[] }>(ENDPOINTS.forms);
  const lastStamp = await getSetting('forms_updated_at');
  if (force || lastStamp !== serverStamp.updated_at) {
    const forms = serverStamp.forms;
    await replaceForms(
      forms.map((f) => ({
        id: f.id,
        code: f.code,
        title: f.title,
        description: f.description ?? null,
        current_version: f.current_version ?? null,
        version: f.version ?? null,
        updated_at: f.updated_at ?? null,
      })),
    );
    for (const f of forms) {
      const sections = f.sections ?? [];
      const n = await saveFormDefinition(f.id, sections);
      result.fields += n;
    }
    result.forms = forms.length;
    result.refreshed = true;
    await setSetting('forms_updated_at', serverStamp.updated_at);
  } else {
    const cached = await getForms();
    result.forms = cached.length;
  }

  // ---- locations (offline cascade) ----
  const loc = await api<{ updated_at: string; districts: unknown[]; blocks: unknown[]; panchayats: unknown[]; villages: unknown[] }>(ENDPOINTS.locations);
  const lastLocStamp = await getSetting('locations_updated_at');
  if (force || lastLocStamp !== loc.updated_at) {
    await replaceLocations({
      districts: loc.districts as never,
      blocks: loc.blocks as never,
      panchayats: loc.panchayats as never,
      villages: loc.villages as never,
    });
    result.locations = true;
    result.refreshed = true;
    await setSetting('locations_updated_at', loc.updated_at);
  }

  // ---- masters ----
  const masters = await api<{ updated_at: string; groups: unknown[]; items: unknown[] }>(ENDPOINTS.masters);
  const lastMasterStamp = await getSetting('masters_updated_at');
  if (force || lastMasterStamp !== masters.updated_at) {
    await replaceMasters({ groups: masters.groups, items: masters.items });
    result.masters = true;
    result.refreshed = true;
    await setSetting('masters_updated_at', masters.updated_at);
  }

  // ---- notifications ----
  const notifs = await api<ServerNotification[]>(ENDPOINTS.notifications);
  await replaceNotifications(notifs.map((n) => ({ id: n.id, title: n.title, body: n.body ?? null, type: n.type ?? null, is_read: n.is_read ? 1 : 0, created_at: n.created_at ?? null })));
  result.notifications = notifs.length;

  return result;
}

/** The user's fixed location scope (for auto-populating cascades). */
export async function fetchLocationScope(): Promise<LocationScope | null> {
  try {
    const res = await api<{ scope: LocationScope }>(ENDPOINTS.locationScope);
    return res.scope ?? null;
  } catch (e) {
    console.warn('location scope fetch failed', e);
    return null;
  }
}

/** Forms list from cache (works offline). */
export async function cachedForms(): Promise<SurveyForm[]> {
  const rows = await getForms();
  return rows.map((r) => ({
    id: r.id,
    code: r.code,
    title: r.title,
    description: r.description ?? null,
    current_version: r.current_version ?? null,
    version: r.version ?? null,
    updated_at: r.updated_at ?? null,
  }));
}

/** Resolve a form by id from cache (offline-safe). */
export async function cachedForm(id: number): Promise<SurveyForm | null> {
  const row = await getForm(id);
  if (!row) return null;
  return {
    id: row.id,
    code: row.code,
    title: row.title,
    description: row.description ?? null,
    current_version: row.current_version ?? null,
    version: row.version ?? null,
  };
}
