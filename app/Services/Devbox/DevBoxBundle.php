<?php

namespace App\Services\Devbox;

use RuntimeException;

class DevBoxBundle
{
    private const string CIPHER = 'aes-256-gcm';

    private const int ITERATIONS = 100_000;

    /**
     * Encrypt a DevBox payload into a secure JSON string using AES-256-GCM.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function encrypt(array $payload, string $passphrase): string
    {
        if (trim($passphrase) === '') {
            throw new RuntimeException('Passphrase cannot be empty.');
        }

        $salt = random_bytes(16);
        $iv = random_bytes(12);
        $key = hash_pbkdf2('sha256', $passphrase, $salt, self::ITERATIONS, 32, true);

        $plaintext = json_encode($payload, JSON_THROW_ON_ERROR);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);

        if ($ciphertext === false) {
            throw new RuntimeException('Failed to encrypt DevBox bundle.');
        }

        return json_encode([
            'larakube_bundle' => 'devbox',
            'version' => 1,
            'kdf' => 'pbkdf2-sha256',
            'iterations' => self::ITERATIONS,
            'cipher' => self::CIPHER,
            'salt' => base64_encode($salt),
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'data' => base64_encode($ciphertext),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Decrypt a DevBox bundle using the passphrase.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException if decryption fails or format is invalid.
     */
    public static function decrypt(string $bundleJson, string $passphrase): array
    {
        $bundle = json_decode($bundleJson, true);
        if (! is_array($bundle) || ($bundle['larakube_bundle'] ?? null) !== 'devbox') {
            throw new RuntimeException('Invalid or unrecognized DevBox bundle format.');
        }

        $salt = base64_decode((string) ($bundle['salt'] ?? ''), true);
        $iv = base64_decode((string) ($bundle['iv'] ?? ''), true);
        $tag = base64_decode((string) ($bundle['tag'] ?? ''), true);
        $ciphertext = base64_decode((string) ($bundle['data'] ?? ''), true);
        $iterations = (int) ($bundle['iterations'] ?? self::ITERATIONS);

        if (! $salt || ! $iv || ! $tag || ! $ciphertext) {
            throw new RuntimeException('Corrupted DevBox bundle metadata.');
        }

        $key = hash_pbkdf2('sha256', $passphrase, $salt, $iterations, 32, true);
        $decrypted = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);

        if ($decrypted === false) {
            throw new RuntimeException('Incorrect passphrase or corrupted bundle.');
        }

        $data = json_decode($decrypted, true);
        if (! is_array($data)) {
            throw new RuntimeException('Invalid decrypted payload.');
        }

        return $data;
    }
}
