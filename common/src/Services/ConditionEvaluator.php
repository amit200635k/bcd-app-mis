<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Server-side evaluation of conditional logic (IF/THEN show/hide/required).
 *
 * Mirrors the client-side engine in mis/builder/preview.php so stored answers
 * always reflect the same rules: hidden fields are dropped before persisting a
 * record and visible mandatory / condition-required fields are enforced.
 *
 * Conditions are read from a form definition's sections
 * (SurveyService::formDefinition() → sections[].fields[].conditions[]), where
 * each condition already carries its resolved target_field_key.
 */
final class ConditionEvaluator
{
    /**
     * Evaluate every condition against the submitted answers.
     *
     * @param array $sections formDefinition()['sections']
     * @param array<string, mixed> $answers field_key => submitted value
     * @return array{visible: array<string,bool>, required: array<string,bool>}
     */
    public static function evaluate(array $sections, array $answers): array
    {
        $visible = [];
        $required = [];

        foreach ($sections as $section) {
            foreach ($section['fields'] as $field) {
                $key = (string) ($field['field_key'] ?? '');
                if ($key === '') {
                    continue;
                }
                $visible[$key] = true;
                $required[$key] = false;
                foreach (($field['conditions'] ?? []) as $cond) {
                    $action = (string) ($cond['action'] ?? 'show');
                    $matched = self::matches($cond, $answers);
                    if ($action === 'show' && $visible[$key] && !$matched) {
                        $visible[$key] = false;
                    } elseif ($action === 'hide' && $visible[$key] && $matched) {
                        $visible[$key] = false;
                    } elseif ($action === 'required' && $matched && !$required[$key]) {
                        $required[$key] = true;
                    }
                }
            }
        }

        return ['visible' => $visible, 'required' => $required];
    }

    /**
     * Fields that must carry a value because they are visible and either
     * declared mandatory or made required by a matching condition.
     *
     * @param array $sections formDefinition()['sections']
     * @param array<string, mixed> $answers final (post-filter) answers
     * @param array|null $evaluated result of self::evaluate() to avoid recomputation
     * @return array<string, list<string>> field_key => error messages
     */
    public static function missingRequired(array $sections, array $answers, ?array $evaluated = null): array
    {
        $evaluated ??= self::evaluate($sections, $answers);
        $errors = [];

        foreach ($sections as $section) {
            foreach ($section['fields'] as $field) {
                $key = (string) ($field['field_key'] ?? '');
                if ($key === '') {
                    continue;
                }
                if (($evaluated['visible'][$key] ?? true) === false) {
                    continue; // hidden fields are never required
                }
                $required = (bool) ($field['is_mandatory'] ?? 0) || !empty($evaluated['required'][$key]);
                if (!$required) {
                    continue;
                }
                if (self::isEmpty($answers[$key] ?? null)) {
                    $errors[$key][] = (string) ($field['label'] ?? $key) . ' is required.';
                }
            }
        }

        return $errors;
    }

    /**
     * @param array $cond a single condition row (operator, condition_value, target_field_key)
     * @param array<string, mixed> $answers
     */
    private static function matches(array $cond, array $answers): bool
    {
        $trigger = (string) ($cond['target_field_key'] ?? '');
        $expected = (string) ($cond['condition_value'] ?? '');
        $actual = $answers[$trigger] ?? null;
        $op = (string) ($cond['operator'] ?? 'equals');

        if (is_array($actual)) {
            return self::matchesArray($op, $actual, $expected);
        }

        $actual = (string) ($actual ?? '');
        return match ($op) {
            'equals'       => $actual === $expected,
            'not_equals'   => $actual !== $expected,
            'in'           => in_array($actual, array_map('trim', explode(',', $expected)), true),
            'not_in'       => !in_array($actual, array_map('trim', explode(',', $expected)), true),
            'greater_than' => is_numeric($actual) && is_numeric($expected) && (float) $actual > (float) $expected,
            'less_than'    => is_numeric($actual) && is_numeric($expected) && (float) $actual < (float) $expected,
            'contains'     => str_contains($actual, $expected),
            default        => true,
        };
    }

    /** Multi-select / cascade answers are submitted as arrays. */
    private static function matchesArray(string $op, array $actual, string $expected): bool
    {
        $values = array_values(array_map(static fn (mixed $v): string => (string) $v, $actual));
        $allowed = array_values(array_filter(array_map('trim', explode(',', $expected)), static fn (string $s): bool => $s !== ''));

        if ($op === 'contains') {
            foreach ($values as $v) {
                if (str_contains($v, $expected)) {
                    return true;
                }
            }
            return false;
        }

        return match ($op) {
            'equals'       => in_array($expected, $values, true) || implode(',', $values) === $expected,
            'not_equals'   => !in_array($expected, $values, true) && implode(',', $values) !== $expected,
            'in'           => $allowed !== [] && array_intersect($values, $allowed) !== [],
            'not_in'       => array_intersect($values, $allowed) === [],
            'greater_than' => $values !== [] && is_numeric($values[0]) && (float) $values[0] > (float) $expected,
            'less_than'    => $values !== [] && is_numeric($values[0]) && (float) $values[0] < (float) $expected,
            default        => true,
        };
    }

    private static function isEmpty(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_array($value)) {
            return $value === [];
        }
        return trim((string) $value) === '';
    }
}
