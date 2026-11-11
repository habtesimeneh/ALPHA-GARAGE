<?php
declare(strict_types=1);

/**
 * Enterprise Cryptographic Service for Sensitive Data Storage.
 *
 * Implements authenticated server-side encryption using AES-256-GCM.
 *  - Key is stored exclusively outside web root (storage/private/encryption.key or GMS_ENCRYPTION_KEY).
 *  - Every encryption generates a unique, cryptographically secure 96-bit nonce (IV).
 *  - Includes a 128-bit authentication tag to verify data integrity before returning decrypted content.
 *  - Supports keyed blind index (HMAC-SHA-256) for searching encrypted records without decrypting in SQL.
 */
final class CryptoService
{
    private const CIPHER = 'aes-256-gcm';
    private const IV_LEN = 12;      // 96-bit IV recommended for GCM
    private const TAG_LEN = 16;     // 128-bit auth tag
    private const VERSION = 'v1';

    private static ?string $cachedKey = null;

    /**
     * Retrieve or generate the master encryption key.
     */
    public static function getKey(): string
    {
        if (self::$cachedKey !== null) {
            return self::$cachedKey;
        }

        // 1. Check environment variable
        $envKey = getenv('GMS_ENCRYPTION_KEY');
        if ($envKey !== false && $envKey !== '') {
            if (!preg_match('/\A[0-9a-fA-F]{64}\z/', $envKey)) {
                throw new RuntimeException('GMS_ENCRYPTION_KEY must be exactly 64 hexadecimal characters.');
            }
            $decodedKey = hex2bin($envKey);
            if ($decodedKey === false) {
                throw new RuntimeException('GMS_ENCRYPTION_KEY is not valid hexadecimal data.');
            }
            return self::$cachedKey = $decodedKey;
        }

        // 2. Check local secure key file in storage/private/
        $keyDir = storage_path('private');
        $keyFile = $keyDir . '/encryption.key';

        if (!is_dir($keyDir) && !mkdir($keyDir, 0700, true) && !is_dir($keyDir)) {
            throw new RuntimeException('Cannot create private encryption-key directory.');
        }

        if (is_file($keyFile)) {
            $raw = file_get_contents($keyFile);
            if ($raw === false || strlen($raw) < 32) {
                throw new RuntimeException('The stored encryption key is unreadable or invalid.');
            }
            return self::$cachedKey = substr($raw, 0, 32);
        }

        // 3. Generate key and persist safely
        $newKey = random_bytes(32);
        if (file_put_contents($keyFile, $newKey, LOCK_EX) !== strlen($newKey)) {
            throw new RuntimeException('Cannot persist the generated encryption key.');
        }
        if (DIRECTORY_SEPARATOR !== '\\' && !chmod($keyFile, 0600)) {
            throw new RuntimeException('Cannot restrict permissions on the encryption key.');
        }
        return self::$cachedKey = $newKey;
    }

    /**
     * Authenticated encryption with AES-256-GCM.
     * Output format: v1:base64(iv):base64(tag):base64(ciphertext)
     */
    public static function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }

        $key = self::getKey();
        $iv = random_bytes(self::IV_LEN);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LEN
        );

        if ($ciphertext === false) {
            throw new RuntimeException('Encryption failed: OpenSSL error');
        }

        return self::VERSION . ':' . base64_encode($iv) . ':' . base64_encode($tag) . ':' . base64_encode($ciphertext);
    }

    /**
     * Authenticated decryption with AES-256-GCM.
     * Returns null if tampering is detected or key is invalid.
     */
    public static function decrypt(string $payload): ?string
    {
        if ($payload === '') {
            return '';
        }

        $parts = explode(':', $payload);
        if (count($parts) !== 4 || $parts[0] !== self::VERSION) {
            // Not encrypted or unrecognized version format: return as-is for backward compatibility
            return $payload;
        }

        [, $ivB64, $tagB64, $cipherB64] = $parts;
        $iv = base64_decode($ivB64, true);
        $tag = base64_decode($tagB64, true);
        $ciphertext = base64_decode($cipherB64, true);

        if ($iv === false || $tag === false || $ciphertext === false || strlen($iv) !== self::IV_LEN || strlen($tag) !== self::TAG_LEN) {
            return null;
        }

        $key = self::getKey();
        $decrypted = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        return $decrypted !== false ? $decrypted : null;
    }

    /**
     * Keyed HMAC-SHA-256 blind index for searching encrypted values without decrypting table records.
     */
    public static function blindIndex(string $value): string
    {
        $normalized = mb_strtolower(trim($value), 'UTF-8');
        return hash_hmac('sha256', $normalized, self::getKey());
    }

    /**
     * Checks if a string has the encrypted format signature.
     */
    public static function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::VERSION . ':') && substr_count($value, ':') === 3;
    }
}
