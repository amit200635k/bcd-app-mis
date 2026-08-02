<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a submitted record fails server-side validation, including
 * conditional (IF/THEN show/hide/required) rules evaluated at store time.
 */
final class ValidationException extends RuntimeException
{
    /**
     * @param array<string, list<string>> $errors field_key => error messages
     */
    public function __construct(private readonly array $errors, string $message = 'Validation failed.')
    {
        parent::__construct($message, 422);
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }
}
