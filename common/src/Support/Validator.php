<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Lightweight field validator with Indian-format helpers (Aadhaar, PAN, etc.).
 */
final class Validator
{
    /** @var array<string, list<string>> */
    private array $errors = [];

    /**
     * @param array<string,mixed> $data
     * @param array<string,string> $rules e.g. ['name' => 'required|min:3', 'email' => 'email']
     */
    public function __construct(private array $data, private array $rules)
    {
        $this->run();
    }

    public static function make(array $data, array $rules): self
    {
        return new self($data, $rules);
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }

    private function run(): void
    {
        foreach ($this->rules as $field => $ruleString) {
            $rules = array_filter(array_map('trim', explode('|', $ruleString)));
            $value = $this->data[$field] ?? null;

            foreach ($rules as $rule) {
                [$name, $param] = array_pad(explode(':', $rule, 2), 2, null);
                if ($param !== null && str_contains($param, ',')) {
                    $param = explode(',', $param);
                }
                $this->check($field, $name, $param, $value);
                if (isset($this->errors[$field])) {
                    break;
                }
            }
        }
    }

    private function addError(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }

    private function valueToString(mixed $value): string
    {
        if (is_string($value) || is_numeric($value)) {
            return trim((string) $value);
        }
        if ($value === null) {
            return '';
        }
        return '';
    }

    private function check(string $field, string $rule, mixed $param, mixed $value): void
    {
        $val = $this->valueToString($value);

        switch ($rule) {
            case 'required':
                if ($value === null || $value === '' || (is_array($value) && $value === [])) {
                    $this->addError($field, 'The field is required.');
                }
                break;

            case 'nullable':
                break;

            case 'string':
                if ($value !== null && !is_string($value) && !is_numeric($value)) {
                    $this->addError($field, 'Must be a string.');
                }
                break;

            case 'array':
                if ($value !== null && !is_array($value)) {
                    $this->addError($field, 'Must be an array.');
                }
                break;

            case 'numeric':
                if ($value !== null && $value !== '' && !is_numeric($value)) {
                    $this->addError($field, 'Must be a number.');
                }
                break;

            case 'integer':
                if ($value !== null && $value !== '' && filter_var($value, FILTER_VALIDATE_INT) === false) {
                    $this->addError($field, 'Must be an integer.');
                }
                break;

            case 'min':
                $min = (float) $param;
                if ($value !== null && $value !== '' && (float) $val < $min) {
                    $this->addError($field, "Must be at least {$min}.");
                }
                break;

            case 'max':
                $max = (float) $param;
                if ($value !== null && $value !== '' && (float) $val > $max) {
                    $this->addError($field, "Must not exceed {$max}.");
                }
                break;

            case 'min_length':
                $len = (int) $param;
                if (mb_strlen($val) < $len) {
                    $this->addError($field, "Must be at least {$len} characters.");
                }
                break;

            case 'max_length':
                $len = (int) $param;
                if (mb_strlen($val) > $len) {
                    $this->addError($field, "Must not exceed {$len} characters.");
                }
                break;

            case 'email':
                if ($value !== null && $value !== '' && filter_var($val, FILTER_VALIDATE_EMAIL) === false) {
                    $this->addError($field, 'Must be a valid email address.');
                }
                break;

            case 'url':
                if ($value !== null && $value !== '' && filter_var($val, FILTER_VALIDATE_URL) === false) {
                    $this->addError($field, 'Must be a valid URL.');
                }
                break;

            case 'regex':
                if ($value !== null && $value !== '' && !preg_match((string) $param, $val)) {
                    $this->addError($field, 'Format is invalid.');
                }
                break;

            case 'in':
                $allowed = is_array($param) ? $param : [$param];
                if ($value !== null && $value !== '' && !in_array($val, $allowed, true)) {
                    $this->addError($field, 'Selected value is not allowed.');
                }
                break;

            case 'date':
                if ($value !== null && $value !== '' && strtotime($val) === false) {
                    $this->addError($field, 'Must be a valid date.');
                }
                break;

            case 'date_after':
                if ($value !== null && $value !== '' && strtotime($val) <= strtotime((string) $param)) {
                    $this->addError($field, "Must be after {$param}.");
                }
                break;

            case 'boolean':
                if ($value !== null && $value !== '' && !in_array(strtolower($val), ['1', '0', 'true', 'false', 'yes', 'no'], true)) {
                    $this->addError($field, 'Must be a boolean.');
                }
                break;

            case 'aadhaar':
                if ($value !== null && $value !== '' && !self::isValidAadhaar($val)) {
                    $this->addError($field, 'Invalid Aadhaar number (Verhoeff check failed).');
                }
                break;

            case 'pan':
                if ($value !== null && $value !== '' && !preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/', $val)) {
                    $this->addError($field, 'Invalid PAN number.');
                }
                break;

            case 'mobile':
                if ($value !== null && $value !== '' && !preg_match('/^[6-9][0-9]{9}$/', $val)) {
                    $this->addError($field, 'Invalid 10-digit mobile number.');
                }
                break;

            case 'pincode':
                if ($value !== null && $value !== '' && !preg_match('/^[1-9][0-9]{5}$/', $val)) {
                    $this->addError($field, 'Invalid 6-digit PIN code.');
                }
                break;
        }
    }

    /** Verhoeff checksum for Aadhaar (last digit is checksum). */
    public static function isValidAadhaar(string $aadhaar): bool
    {
        if (!preg_match('/^[0-9]{12}$/', $aadhaar)) {
            return false;
        }
        $d = [[0, 1, 2, 3, 4, 5, 6, 7, 8, 9],
              [1, 2, 3, 4, 0, 6, 7, 8, 9, 5],
              [2, 3, 4, 0, 1, 7, 8, 9, 5, 6],
              [3, 4, 0, 1, 2, 8, 9, 5, 6, 7],
              [4, 0, 1, 2, 3, 9, 5, 6, 7, 8],
              [5, 9, 8, 7, 6, 0, 4, 3, 2, 1],
              [6, 5, 9, 8, 7, 1, 0, 4, 3, 2],
              [7, 6, 5, 9, 8, 2, 1, 0, 4, 3],
              [8, 7, 6, 5, 9, 3, 2, 1, 0, 4],
              [9, 8, 7, 6, 5, 4, 3, 2, 1, 0]];
        $p = [[0, 1, 2, 3, 4, 5, 6, 7, 8, 9],
              [1, 5, 7, 6, 2, 8, 3, 0, 9, 4],
              [5, 8, 0, 3, 7, 9, 6, 1, 4, 2],
              [8, 9, 1, 6, 0, 4, 3, 5, 2, 7],
              [9, 4, 5, 3, 1, 2, 6, 8, 7, 0],
              [4, 2, 8, 6, 5, 7, 3, 9, 0, 1],
              [2, 7, 9, 3, 8, 0, 6, 4, 1, 5],
              [7, 0, 4, 6, 9, 1, 3, 2, 5, 8]];
        $inv = [0, 4, 3, 2, 1, 5, 6, 7, 8, 9];

        $c = 0;
        $digits = array_map('intval', str_split($aadhaar));
        for ($i = 0, $n = count($digits); $i < $n; $i++) {
            $c = $d[$c][$p[($i % 8)][$digits[$n - 1 - $i]]];
        }
        return $inv[$c] === 0;
    }
}
