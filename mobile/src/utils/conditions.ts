import {SurveyField, SurveySection} from '../types/api';

interface EvaluationResult {
  visible: Record<string, boolean>;
  required: Record<string, boolean>;
}

/**
 * Client-side mirror of the server's ConditionEvaluator so the offline form
 * behaves identically to the validated server-side store. A field is:
 *  - shown  by default, hidden when a 'hide' rule matches or all its 'show'
 *           rules fail;
 *  - condition-required when a 'required' rule matches.
 * All operators compare against the STRING representation of the submitted
 * value (arrays are JSON-serialized), matching the backend implementation.
 */
export function evaluateConditions(sections: SurveySection[], answers: Record<string, unknown>): EvaluationResult {
  const visible: Record<string, boolean> = {};
  const required: Record<string, boolean> = {};

  for (const section of sections) {
    for (const field of section.fields) {
      const key = field.field_key;
      visible[key] = true;
      required[key] = false;

      if (!field.conditions || field.conditions.length === 0) {
        continue;
      }

      let matched = false;
      for (const cond of field.conditions) {
        if (!cond.target_field_key) {
          continue;
        }
        const trigger = answers[cond.target_field_key];
        if (compare(cond.operator, trigger, cond.condition_value)) {
          matched = true;
          if (cond.action === 'hide') {
            visible[key] = false;
          } else if (cond.action === 'required') {
            required[key] = true;
          }
        }
      }

      // 'show' semantics: a field with only 'show' rules is visible only when
      // at least one of them matches.
      const showRules = field.conditions.filter((c) => c.action === 'show');
      if (showRules.length > 0 && !matched) {
        visible[key] = false;
      }
    }
  }

  return {visible, required};
}

function toComparable(value: unknown): string {
  if (value === null || value === undefined) {
    return '';
  }
  if (typeof value === 'object') {
    return JSON.stringify(value);
  }
  return String(value);
}

function compare(operator: string, actual: unknown, expected: string): boolean {
  const a = toComparable(actual).trim();
  const b = (expected ?? '').trim();

  switch (operator) {
    case 'equals':
      return a === b;
    case 'not_equals':
      return a !== b;
    case 'contains':
      return a.includes(b);
    case 'not_contains':
      return !a.includes(b);
    case 'starts_with':
      return a.startsWith(b);
    case 'ends_with':
      return a.endsWith(b);
    case 'empty':
      return a === '';
    case 'not_empty':
      return a !== '';
    case 'greater':
      return a !== '' && b !== '' && parseFloat(a) > parseFloat(b);
    case 'less':
      return a !== '' && b !== '' && parseFloat(a) < parseFloat(b);
    case 'in':
      return b.split(',').map((s) => s.trim()).includes(a);
    case 'not_in':
      return !b.split(',').map((s) => s.trim()).includes(a);
    default:
      return false;
  }
}

/** Visible mandatory OR condition-required fields that are currently empty. */
export function missingRequired(
  sections: SurveySection[],
  answers: Record<string, unknown>,
  evaluated: EvaluationResult,
): Record<string, string> {
  const missing: Record<string, string> = {};
  for (const section of sections) {
    for (const field of section.fields) {
      if (!evaluated.visible[field.field_key]) {
        continue;
      }
      const isRequired = field.is_mandatory === 1 || evaluated.required[field.field_key] === true;
      if (!isRequired) {
        continue;
      }
      const value = answers[field.field_key];
      if (value === undefined || value === null || value === '' || (Array.isArray(value) && value.length === 0)) {
        missing[field.field_key] = `${field.label} is required.`;
      }
    }
  }
  return missing;
}

export function isFieldVisible(field: SurveyField, evaluated: EvaluationResult): boolean {
  return evaluated.visible[field.field_key] !== false;
}
