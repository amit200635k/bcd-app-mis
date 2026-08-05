import type { FormField } from '../api/types';

export interface FieldCondition {
  target_field_id?: number | null;
  target_field_key?: string | null;
  operator: string;
  condition_value?: string | null;
  action: string;
}

/**
 * Mirrors the server-side ConditionEvaluator so client validation and
 * server-side persistence agree (hidden answers are dropped, visible
 * mandatory/condition-required fields are enforced).
 */

function matches(cond: FieldCondition, answers: Record<string, unknown>): boolean {
  const trigger = String(cond.target_field_key ?? '');
  const expected = String(cond.condition_value ?? '');
  const actual = answers[trigger];
  const op = String(cond.operator ?? 'equals');

  if (Array.isArray(actual)) {
    return matchesArray(op, actual, expected);
  }

  const actualStr = String(actual ?? '');
  switch (op) {
    case 'equals':
      return actualStr === expected;
    case 'not_equals':
      return actualStr !== expected;
    case 'in':
      return expected.split(',').map((s) => s.trim()).includes(actualStr);
    case 'not_in':
      return !expected.split(',').map((s) => s.trim()).includes(actualStr);
    case 'greater_than':
      return isNumeric(actualStr) && isNumeric(expected) && Number(actualStr) > Number(expected);
    case 'less_than':
      return isNumeric(actualStr) && isNumeric(expected) && Number(actualStr) < Number(expected);
    case 'contains':
      return actualStr.includes(expected);
    default:
      return true;
  }
}

function matchesArray(op: string, actual: unknown[], expected: string): boolean {
  const values = actual.map((v) => String(v));
  const allowed = expected
    .split(',')
    .map((s) => s.trim())
    .filter((s) => s !== '');

  if (op === 'contains') {
    return values.some((v) => v.includes(expected));
  }
  switch (op) {
    case 'equals':
      return values.includes(expected) || values.join(',') === expected;
    case 'not_equals':
      return !values.includes(expected) && values.join(',') !== expected;
    case 'in':
      return allowed.length > 0 && values.some((v) => allowed.includes(v));
    case 'not_in':
      return !values.some((v) => allowed.includes(v));
    case 'greater_than':
      return values.length > 0 && isNumeric(values[0]) && Number(values[0]) > Number(expected);
    case 'less_than':
      return values.length > 0 && isNumeric(values[0]) && Number(values[0]) < Number(expected);
    default:
      return true;
  }
}

function isNumeric(v: string): boolean {
  return v !== '' && !Number.isNaN(Number(v));
}

export interface Evaluation {
  visible: Map<string, boolean>;
  required: Map<string, boolean>;
}

/**
 * Evaluate every condition in the form against the answers.
 * Same algorithm as ConditionEvaluator::evaluate().
 */
export function evaluateConditions(fields: FormField[], answers: Record<string, unknown>): Evaluation {
  const visible = new Map<string, boolean>();
  const required = new Map<string, boolean>();

  for (const field of fields) {
    const key = field.field_key;
    if (!key) continue;
    visible.set(key, true);
    required.set(key, false);
    for (const cond of field.conditions ?? []) {
      const action = String(cond.action ?? 'show');
      const matched = matches(cond, answers);
      if (action === 'show' && visible.get(key) && !matched) {
        visible.set(key, false);
      } else if (action === 'hide' && visible.get(key) && matched) {
        visible.set(key, false);
      } else if (action === 'required' && matched && !required.get(key)) {
        required.set(key, true);
      }
    }
  }

  return { visible, required };
}

/** Missing visible mandatory / condition-required fields (field_key → messages). */
export function missingRequired(
  fields: FormField[],
  answers: Record<string, unknown>,
  evaluated?: Evaluation,
): Record<string, string[]> {
  const ev = evaluated ?? evaluateConditions(fields, answers);
  const errors: Record<string, string[]> = {};

  for (const field of fields) {
    const key = field.field_key;
    if (!key) continue;
    if (ev.visible.get(key) === false) continue;
    const required = Boolean(field.is_mandatory) || Boolean(ev.required.get(key));
    if (!required) continue;
    if (isEmpty(answers[key])) {
      errors[key] = [`${field.label || key} is required.`];
    }
  }
  return errors;
}

function isEmpty(value: unknown): boolean {
  if (value === null || value === undefined) return true;
  if (Array.isArray(value)) return value.length === 0;
  return String(value).trim() === '';
}
