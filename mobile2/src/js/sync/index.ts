import { api, ENDPOINTS, ApiError } from '../api/client';
import type { RecordCreated } from '../api/types';
import {
  getPendingQueue,
  getQueueStats,
  setQueueStatus,
  setQueueRetrying,
  getRecord,
  getRecordAnswers,
  getRecordGps,
  getAttachment,
  updateServerRecordId,
  updateRecordStatus,
  updateAttachmentUploaded,
  audit,
  type QueueItem,
  type LocalAnswer,
} from '../db/repos';
import { readAttachmentBlob } from '../native/media';
import { notify } from '../native/notify';
import { isConnected } from '../native/network';
import { SYNC } from '../config';

/**
 * Offline-first sync engine (M8). Drains the local queue:
 *
 *  - 'upsert'             → POST /v1/records (upsert-by-uuid). Media answers
 *                           are sent as empty strings: the server creates the
 *                           answer row, then the photo upload links the file
 *                           and writes value_json back itself.
 *  - 'upload_attachment'  → POST /v1/records/{id}/photos (multipart). Needs
 *                           the server record id captured from the upsert
 *                           response (queue order guarantees upsert first).
 *
 * 422 (validation) → permanent failure. Anything else → exponential backoff
 * retry up to SYNC.maxRetries, then failed. A local notification reports the
 * outcome.
 */

const MEDIA_WITH_ATTACHMENT = new Set(['camera', 'signature', 'file_upload']);

const CATEGORY_MAP: Record<string, string> = {
  camera: 'photo',
  signature: 'signature',
  file: 'file',
  barcode: 'barcode',
  qr_code: 'qr',
};

let syncing = false;

/** True when a sync run is currently in progress. */
export function isSyncing(): boolean {
  return syncing;
}

/** Manual trigger. Returns true when the queue drained completely. */
export async function syncNow(): Promise<{ processed: number; failed: number }> {
  if (syncing) {
    return { processed: 0, failed: 0 };
  }
  if (!(await isConnected())) {
    return { processed: 0, failed: 0 };
  }
  syncing = true;
  let processed = 0;
  let failed = 0;
  try {
    for (;;) {
      const items = await getPendingQueue();
      if (items.length === 0) {
        break;
      }
      for (const item of items) {
        try {
          if (item.action === 'upsert') {
            await handleUpsert(item);
          } else {
            await handleAttachment(item);
          }
          await setQueueStatus(item.id, 'synced');
          processed++;
        } catch (e) {
          if (e instanceof ApiError && e.status === 422) {
            await setQueueStatus(item.id, 'failed', e.message);
            failed++;
            continue;
          }
          await scheduleRetry(item, e);
          failed++;
          break; // stop draining until the retry window elapses
        }
      }
      // after a retry was scheduled, leave the rest for the next run
      break;
    }
    if (failed > 0 || processed > 0) {
      const stats = await getQueueStats();
      await notify(
        failed > 0 ? 'Sync needs attention' : 'Sync complete',
        `${processed} item${processed === 1 ? '' : 's'} synced${failed > 0 ? `, ${failed} failed` : ''}. ${stats.pending} still pending.`,
      );
    }
  } finally {
    syncing = false;
  }
  return { processed, failed };
}

/* ---------------------------------------------------------------------- */
/* per-item handlers                                                       */
/* ---------------------------------------------------------------------- */

interface UpsertPayload {
  form_id?: number;
  form_version_id?: number;
  status?: string;
  device_id?: string;
}

async function handleUpsert(item: QueueItem): Promise<void> {
  const payload = safeParse<UpsertPayload>(item.payload_json) ?? {};
  const record = await getRecord(item.record_uuid);
  if (!record) {
    throw new ApiError('Local record no longer exists.', 404);
  }

  const answerRows = await getRecordAnswers(item.record_uuid);
  const answers = toServerAnswers(answerRows);
  const gps = await getRecordGps(item.record_uuid);

  const created = await api<RecordCreated>(ENDPOINTS.records, {
    method: 'POST',
    body: {
      record_uuid: item.record_uuid,
      form_id: payload.form_id ?? record.form_id,
      form_version_id: payload.form_version_id ?? record.form_version_id,
      status: payload.status ?? record.status ?? 'submitted',
      device_id: payload.device_id ?? record.device_id ?? null,
      answers,
      gps,
    },
  });

  if (created.record_id > 0) {
    await updateServerRecordId(item.record_uuid, created.record_id);
  }
  await updateRecordStatus(item.record_uuid, created.status ?? 'submitted', new Date().toISOString());
  await audit('record.synced', { record_uuid: item.record_uuid, record_id: created.record_id });
}

interface AttachmentPayload {
  attachment_id?: number;
  field_key?: string;
  category?: string;
}

async function handleAttachment(item: QueueItem): Promise<void> {
  const payload = safeParse<AttachmentPayload>(item.payload_json) ?? {};
  const at = await getAttachment(payload.attachment_id ?? 0);
  if (!at) {
    throw new ApiError('Attachment no longer exists.', 404);
  }
  const record = await getRecord(item.record_uuid);
  const serverRecordId = record?.server_record_id;
  if (!serverRecordId) {
    throw new ApiError('Record not yet on the server — upsert will follow.', 409);
  }

  const blob = await readAttachmentBlob(at.local_uri ?? '', at.mime_type ?? 'application/octet-stream');
  const fd = new FormData();
  fd.append('files[]', blob, at.file_name ?? `file_${at.id}`);
  fd.append('category', CATEGORY_MAP[at.category] ?? 'photo');
  if (at.field_key) {
    fd.append('field_key', at.field_key);
  }

  const res = await api<{ images: Array<{ id: number; file_path: string; answer_id?: number | null }> }>(
    ENDPOINTS.recordPhotos(serverRecordId),
    { method: 'POST', formData: fd },
  );
  const img = res.images?.[0];
  if (img?.id) {
    await updateAttachmentUploaded(at.id ?? 0, img.id, img.file_path);
  }
  await audit('attachment.synced', { record_uuid: item.record_uuid, attachment_id: at.id, image_id: img?.id });
}

/* ---------------------------------------------------------------------- */
/* helpers                                                                 */
/* ---------------------------------------------------------------------- */

/** Local answer rows → server answer map (RecordService::normalize*). */
function toServerAnswers(rows: LocalAnswer[]): Record<string, unknown> {
  const out: Record<string, unknown> = {};
  for (const row of rows) {
    const type = row.field_type ?? '';
    if (MEDIA_WITH_ATTACHMENT.has(type)) {
      // sentinel for the photo-upload answer row; never send the local ref
      out[row.field_key] = '';
      continue;
    }
    if (row.value_json) {
      try {
        out[row.field_key] = JSON.parse(row.value_json);
        continue;
      } catch {
        // fall through to scalar columns
      }
    }
    if (row.value_number !== null && row.value_number !== undefined) {
      out[row.field_key] = row.value_number;
    } else if (row.value_date) {
      out[row.field_key] = row.value_date;
    } else {
      out[row.field_key] = row.value_text ?? '';
    }
  }
  return out;
}

function safeParse<T>(json: string | null | undefined): T | null {
  if (!json) return null;
  try {
    return JSON.parse(json) as T;
  } catch {
    return null;
  }
}

async function scheduleRetry(item: QueueItem, err: unknown): Promise<void> {
  const message = err instanceof Error ? err.message : String(err);
  const retryCount = (item.retry_count ?? 0) + 1;
  if (retryCount > SYNC.maxRetries) {
    await setQueueStatus(item.id, 'failed', message);
    await audit('sync.permanent_failure', { record_uuid: item.record_uuid, action: item.action, error: message });
    return;
  }
  const delayMs = SYNC.retryBaseDelayMs * Math.pow(2, retryCount - 1);
  const retryAt = new Date(Date.now() + delayMs).toISOString();
  await setQueueRetrying(item.id, retryCount, retryAt, message);
}
