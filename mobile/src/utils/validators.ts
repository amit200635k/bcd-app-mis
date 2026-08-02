import {SurveyField} from '../types/api';

/**
 * Normalize a submitted answer to the shape the backend's RecordService
 * expects (see normalizeValue/normalizeMaster/normalizeLocation in
 * common/src/Services/RecordService.php):
 *  - master        -> {master_id, name}
 *  - location_cascade -> {district_id, district_name, block_id, ...} (id + name per level)
 *  - arrays        -> passed through (stored as JSON by the backend)
 *  - scalars       -> passed through
 */
export function normalizeAnswer(field: SurveyField, value: unknown): unknown {
  if (field.type === 'master') {
    const v = value as {master_id?: number | string; name?: string} | null | undefined;
    if (v && (v.master_id || v.name)) {
      return {master_id: Number(v.master_id), name: v.name ?? ''};
    }
    return value;
  }

  if (field.type === 'location_cascade') {
    const v = value as Record<string, unknown> | null | undefined;
    if (v && typeof v === 'object') {
      const out: Record<string, unknown> = {};
      for (const level of ['district', 'block', 'panchayat', 'village']) {
        const id = v[`${level}_id`];
        const name = v[`${level}_name`] ?? v[level];
        if (id || name) {
          out[`${level}_id`] = id ? Number(id) : null;
          out[`${level}_name`] = typeof name === 'string' && name !== '' ? name : null;
        }
      }
      return out;
    }
    return value;
  }

  return value;
}

/** Client-side field validations mirroring the backend Validator rules. */
export function validateField(field: SurveyField, value: unknown): string | null {
  const empty = value === undefined || value === null || value === '' || (Array.isArray(value) && value.length === 0);

  if (field.is_mandatory === 1 && empty) {
    return `${field.label} is required.`;
  }
  if (empty) {
    return null;
  }

  const str = Array.isArray(value) ? value.join(',') : String(value);

  for (const v of field.validations ?? []) {
    const rule = v.rule;
    const p = v.rule_value ?? '';

    switch (rule) {
      case 'min_length':
        if (str.length < Number(p)) {
          return v.error_message ?? `${field.label} must be at least ${p} characters.`;
        }
        break;
      case 'max_length':
        if (str.length > Number(p)) {
          return v.error_message ?? `${field.label} must not exceed ${p} characters.`;
        }
        break;
      case 'numeric':
        if (str !== '' && Number.isNaN(Number(str))) {
          return v.error_message ?? `${field.label} must be a number.`;
        }
        break;
      case 'min':
        if (str !== '' && Number(str) < Number(p)) {
          return v.error_message ?? `${field.label} must be at least ${p}.`;
        }
        break;
      case 'max':
        if (str !== '' && Number(str) > Number(p)) {
          return v.error_message ?? `${field.label} must not exceed ${p}.`;
        }
        break;
      case 'email':
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(str)) {
          return v.error_message ?? `${field.label} must be a valid email address.`;
        }
        break;
      case 'mobile':
        if (!/^[6-9][0-9]{9}$/.test(str)) {
          return v.error_message ?? `${field.label} must be a valid 10-digit mobile number.`;
        }
        break;
      case 'pincode':
        if (!/^[1-9][0-9]{5}$/.test(str)) {
          return v.error_message ?? `${field.label} must be a valid 6-digit PIN code.`;
        }
        break;
      case 'aadhaar':
        if (!/^[0-9]{12}$/.test(str)) {
          return v.error_message ?? `${field.label} must be a valid 12-digit Aadhaar number.`;
        }
        break;
      case 'regex':
        if (p !== '' && !new RegExp(p).test(str)) {
          return v.error_message ?? `${field.label} has an invalid format.`;
        }
        break;
      default:
        break;
    }
  }

  return null;
}
