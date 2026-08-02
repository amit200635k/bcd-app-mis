<?php

declare(strict_types=1);

namespace App\Security;

use RuntimeException;

/**
 * Lightweight JWT (HS256) implementation.
 */
final class Jwt
{
    public static function encode(array $payload, int $ttl): string
    {
        $secret = (string) config('jwt.secret');
        $now = time();
        $claims = [
            'iss' => config('jwt.issuer'),
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $ttl,
        ] + $payload;

        $header = self::base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $body   = self::base64UrlEncode(json_encode($claims, JSON_UNESCAPED_SLASHES));
        $sig    = self::base64UrlEncode(hash_hmac('sha256', $header . '.' . $body, $secret, true));

        return $header . '.' . $body . '.' . $sig;
    }

    /**
     * Decode and validate a token.
     * @return array<string,mixed> claims
     * @throws RuntimeException on invalid/expired token
     */
    public static function decode(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new RuntimeException('Malformed token.');
        }
        [$header, $body, $signature] = $parts;

        $expected = self::base64UrlEncode(hash_hmac('sha256', $header . '.' . $body, (string) config('jwt.secret'), true));
        if (!hash_equals($expected, $signature)) {
            throw new RuntimeException('Invalid token signature.');
        }

        $claims = json_decode(self::base64UrlDecode($body), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($claims)) {
            throw new RuntimeException('Invalid token payload.');
        }

        $now = time();
        if (isset($claims['exp']) && $claims['exp'] < $now) {
            throw new RuntimeException('Token has expired.');
        }
        if (isset($claims['nbf']) && $claims['nbf'] > $now) {
            throw new RuntimeException('Token not yet valid.');
        }

        return $claims;
    }

    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $data): string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        if ($decoded === false) {
            throw new RuntimeException('Invalid base64url data.');
        }
        return $decoded;
    }
}
