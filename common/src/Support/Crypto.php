<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Simple encryption/decryption for storing sensitive config values.
 * Uses AES-256-CBC with a key derived from app secret.
 */
final class Crypto
{
    private const CIPHER = 'aes-256-cbc';
    private const KEY_LENGTH = 32; // 256 bits
    private const IV_LENGTH = 16;  // 128 bits

    private static function getKey(): string
    {
        $appKey = config('app.key');
        if (!$appKey) {
            throw new RuntimeException('app.key not configured in config/app.php');
        }
        // Derive a 32-byte key from the app key
        return hash('sha256', $appKey, true);
    }

    /**
     * Encrypt a plaintext string.
     * Returns base64 encoded string: iv + ciphertext
     */
    public static function encrypt(string $plaintext): string
    {
        $key = self::getKey();
        $iv = random_bytes(self::IV_LENGTH);
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) {
            throw new RuntimeException('Encryption failed: ' . openssl_error_string());
        }
        // Prepend IV to ciphertext and base64 encode
        return base64_encode($iv . $ciphertext);
    }

    /**
     * Decrypt a base64 encoded string.
     * Expects format: iv (16 bytes) + ciphertext
     */
    public static function decrypt(string $ciphertextB64): string
    {
        $key = self::getKey();
        $data = base64_decode($ciphertextB64, true);
        if ($data === false) {
            throw new RuntimeException('Invalid base64 ciphertext');
        }
        if (strlen($data) < self::IV_LENGTH) {
            throw new RuntimeException('Ciphertext too short');
        }
        $iv = substr($data, 0, self::IV_LENGTH);
        $ciphertext = substr($data, self::IV_LENGTH);
        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);
        if ($plaintext === false) {
            throw new RuntimeException('Decryption failed: ' . openssl_error_string());
        }
        return $plaintext;
    }
}