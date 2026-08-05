import type { FormField } from '../api/types';

export type AnswerValue = string | number | boolean | unknown[] | Record<string, unknown> | null;

export interface FieldErrors {
  [fieldKey: string]: string[];
}

/**
 * Client-side validators mirroring common/src/Support/Validator.php plus the
 * mandatory/condition rules evaluated by the form engine.
 */

function isEmpty(value: AnswerValue): boolean {
  if (value === null || value === undefined || value === '') return true;
  if (Array.isArray(value)) return value.length === 0;
  return false;
}

function str(value: AnswerValue): string {
  return value === null || value === undefined ? '' : String(value).trim();
}

/** Verhoeff checksum for Aadhaar (mirror of Validator::isValidAadhaar). */
export function isValidAadhaar(aadhaar: string): boolean {
  if (!/^\d{12}$/.test(aadhaar)) return false;
  const d = [
    [0, 1, 2, 3, 4, 5, 6, 7, 8, 9], [1, 2, 3, 4, 0, 6, 7, 8, 9, 5],
    [2, 3, 4, 0, 1, 7, 8, 9, 5, 6], [3, 4, 0, 1, 2, 8, 9, 5, 6, 7],
    [4, 0, 1, 2, 3, 9, 5, 6, 7, 8], [5, 9, 8, 7, 6, 0, 4, 3, 2, 1],
    [6, 5, 9, 8, 7, 1, 0, 4, 3, 2], [7, 6, 5, 9, 8, 2, 1, 0, 4, 3],
    [8, 7, 6, 5, 9, 3, 2, 1, 0, 4], [9, 8, 7, 6, 5, 4, 3, 2, 1, 0],
  ];
  const p = [
    [0, 1, 2, 3, 4, 5, 6, 7, 8, 9], [1, 5, 7, 6, 2, 8, 3, 0, 9, 4],
    [5, 8, 0, 3, 7, 9, 6, 1, 4, 2], [8, 9, 1, 6, 0, 4, 3, 5, 2, 7],
    [9, 4, 5, 3, 1, 2, 6, 8, 7, 0], [4, 2, 8, 6, 5, 7, 3, 9, 0, 1],
    [2, 7, 9, 3, 8, 0, 6, 4, 1, 5], [7, 0, 4, 6, 9, 1, 3, 2, 5, 8],
  ];
  const inv = [0, 4, 3, 2, 1, 5, 6, 7, 8, 9];
  let c = 0;
  const digits = aadhaar.split('').map((x) => Number(x));
  for (let i = 0, n = digits.length; i < n; i++) {
    c = d[c][p[i % 8][digits[n - 1 - i]]];
  }
  return inv[c] === 0;
}

function checkRule(rule: string, param: string | null, value: AnswerValue): boolean {
  if (rule === 'nullable') return true;
  if (isEmpty(value)) return true; // presence is handled by 'required'
  const v = str(value);

  switch (rule) {
    case 'required':
      return !isEmpty(value);
    case 'string':
      return typeof value === 'string' || typeof value === 'number';
    case 'array':
      return Array.isArray(value);
    case 'numeric':
      return v !== '' && !Number.isNaN(Number(v));
    case 'integer':
      return v !== '' && Number.isInteger(Number(v));
    case 'min': {
      const min = Number(param);
      return !Number.isNaN(min) && Number(v) >= min;
    }
    case 'max': {
      const max = Number(param);
      return !Number.isNaN(max) && Number(v) <= max;
    }
    case 'min_length': {
      const len = Number(param);
      return v.length >= len;
    }
    case 'max_length': {
      const len = Number(param);
      return v.length <= len;
    }
    case 'email':
      return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);
    case 'url':
      try {
        new URL(v);
        return true;
      } catch {
        return false;
      }
    case 'regex':
      try {
        return new RegExp(String(param)).test(v);
      } catch {
        return true;
      }
    case 'in':
      return String(param).split(',').includes(v);
    case 'date':
      return !Number.isNaN(Date.parse(v));
    case 'date_after':
      return Date.parse(v) > Date.parse(String(param));
    case 'boolean':
      return ['1', '0', 'true', 'false', 'yes', 'no'].includes(v.toLowerCase());
    case 'aadhaar':
      return isValidAadhaar(v);
    case 'pan':
      return /^[A-Z]{5}[0-9]{4}[A-Z]{1}$/.test(v);
    case 'mobile':
      return /^[6-9][0-9]{9}$/.test(v);
    case 'pincode':
      return /^[1-9][0-9]{5}$/.test(v);
    default:
      return true;
  }
}

/**
 * Validate a single field's answer against its rule metadata.
 * Returns error messages (empty array when valid).
 */
export function validateField(field: FormField, value: AnswerValue): string[] {
  const errors: string[] = [];
  for (const v of field.validations ?? []) {
    const rule = String(v.rule ?? '');
    const ok = checkRule(rule, v.rule_value ?? null, value);
    if (!ok) {
      errors.push(v.error_message ?? messageFor(rule));
    }
  }
  return errors;
}

function messageFor(rule: string): string {
  switch (rule) {
    case 'numeric': return 'Must be a number.';
    case 'integer': return 'Must be an integer.';
    case 'email': return 'Must be a valid email address.';
    case 'url': return 'Must be a valid URL.';
    case 'mobile': return 'Invalid 10-digit mobile number.';
    case 'pincode': return 'Invalid 6-digit PIN code.';
    case 'aadhaar': return 'Invalid Aadhaar number.';
    case 'pan': return 'Invalid PAN number.';
    default: return 'Value is invalid.';
  }
}
