<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Minimal e-mail sender built on PHP's native mail().
 * Subject/body are subject-injection sanitised; headers fixed to prevent
 * header injection. Used for password-reset notifications.
 */
final class Mail
{
    public static function send(string $to, string $subject, string $bodyHtml): bool
    {
        $driver = config('mail.driver', 'native');
        if ($driver !== 'native') {
            return false;
        }
        $from = (string) config('mail.from', 'noreply@bcd-app');
        $fromName = (string) config('mail.from_name', 'BCD Survey Platform');

        // Sanitise to prevent header/CRLF injection.
        $subjectSanitized = str_replace(["\r", "\n"], ' ', $subject);
        $fromNameSanitized = preg_replace('/[^\pL\pN _.-]/u', '', $fromName) ?? $fromName;
        $toSanitized = preg_replace('/[^\pL\pN_.@+-]/u', '', (string) $to) ?? (string) $to;

        $headers = "MIME-Version: 1.0\r\n"
            . "Content-type: text/html; charset=UTF-8\r\n"
            . "From: {$fromNameSanitized} <{$from}>\r\n"
            . "Reply-To: {$from}\r\n";

        return mail($toSanitized, $subjectSanitized, $bodyHtml, $headers);
    }
}
