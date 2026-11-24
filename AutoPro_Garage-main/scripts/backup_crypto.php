<?php
/**
 * Alpha Garage Backup Cryptography Utility
 *
 * Implements authenticated encryption using AES-256-GCM with:
 * - PBKDF2-SHA256 key derivation (100,000 iterations)
 * - 96-bit cryptographically secure random nonce/IV
 * - 128-bit authentication tag
 * - Explicit format and version metadata header ('AGY_GCM1')
 * - Fail-closed decryption: aborts with code 1 before writing any plaintext
 *   if authentication fails, key is wrong, or ciphertext is tampered.
 * - Proactive payload size verification (max 250 MB single-pass payload)
 *   and CLI memory management to prevent out-of-memory crashes.
 *
 * Usage:
 *   php backup_crypto.php encrypt <input_file> <output_file> <passphrase_file>
 *   php backup_crypto.php decrypt <input_file> <output_file> <passphrase_file>
 *   php backup_crypto.php verify  <input_file> <passphrase_file>
 */

declare(strict_types=1);

class BackupCrypto {
    public const MAGIC = 'AGY_GCM1';
    public const ITERATIONS = 100000;
    public const CIPHER = 'aes-256-gcm';
    public const SALT_LEN = 16;
    public const IV_LEN = 12;
    public const TAG_LEN = 16;

    /**
     * Maximum supported single-pass backup payload size: 250 MB.
     * Prevents unexpected PHP memory exhaustion on multi-gigabyte files.
     */
    public const MAX_FILE_SIZE = 250 * 1024 * 1024;

    private static function ensureMemoryLimit(): void {
        $current = ini_get('memory_limit');
        if ($current !== '-1') {
            @ini_set('memory_limit', '512M');
        }
    }

    public static function encryptFile(string $inputFile, string $outputFile, string $passphraseFile): int {
        self::ensureMemoryLimit();

        if (!is_file($inputFile) || !is_readable($inputFile)) {
            fwrite(STDERR, "ERROR: Input file does not exist or is not readable: {$inputFile}\n");
            return 1;
        }

        $fileSize = filesize($inputFile);
        if ($fileSize > self::MAX_FILE_SIZE) {
            $sizeMB = round($fileSize / (1024 * 1024), 2);
            $maxMB = round(self::MAX_FILE_SIZE / (1024 * 1024), 2);
            fwrite(STDERR, "ERROR: Input file ({$sizeMB} MB) exceeds maximum supported single-pass backup payload size ({$maxMB} MB). For large archives, split dumps or use external streaming tools.\n");
            return 1;
        }

        $passphrase = self::readPassphrase($passphraseFile);
        if ($passphrase === null) {
            return 1;
        }

        $tempOutput = null;
        try {
            $plaintext = file_get_contents($inputFile);
            if ($plaintext === false) {
                fwrite(STDERR, "ERROR: Failed to read input file: {$inputFile}\n");
                return 1;
            }

            $salt = random_bytes(self::SALT_LEN);
            $iv = random_bytes(self::IV_LEN);

            $key = hash_pbkdf2('sha256', $passphrase, $salt, self::ITERATIONS, 32, true);

            $tag = '';
            $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, self::MAGIC);
            if ($ciphertext === false || strlen($tag) !== self::TAG_LEN) {
                fwrite(STDERR, "ERROR: AES-256-GCM encryption operation failed\n");
                return 1;
            }

            // Format: MAGIC (8B) || SALT (16B) || ITERATIONS (4B BE) || IV (12B) || TAG (16B) || CIPHERTEXT
            $header = self::MAGIC . $salt . pack('N', self::ITERATIONS) . $iv . $tag;

            $tempOutput = $outputFile . '.tmp.' . bin2hex(random_bytes(6));
            $written = file_put_contents($tempOutput, $header . $ciphertext);
            if ($written === false) {
                @unlink($tempOutput);
                fwrite(STDERR, "ERROR: Failed to write encrypted output: {$outputFile}\n");
                return 1;
            }

            @chmod($tempOutput, 0600);
            if (!rename($tempOutput, $outputFile)) {
                @unlink($tempOutput);
                fwrite(STDERR, "ERROR: Failed to move temporary output to final path: {$outputFile}\n");
                return 1;
            }

            return 0;
        } catch (Throwable $e) {
            if ($tempOutput !== null && file_exists($tempOutput)) {
                @unlink($tempOutput);
            }
            fwrite(STDERR, "ERROR: Encryption failed with exception: " . $e->getMessage() . "\n");
            return 1;
        }
    }

    public static function decryptFile(string $inputFile, string $outputFile, string $passphraseFile): int {
        self::ensureMemoryLimit();

        if (!is_file($inputFile) || !is_readable($inputFile)) {
            fwrite(STDERR, "ERROR: Encrypted input file does not exist or is not readable: {$inputFile}\n");
            return 1;
        }

        $fileSize = filesize($inputFile);
        if ($fileSize > self::MAX_FILE_SIZE) {
            $sizeMB = round($fileSize / (1024 * 1024), 2);
            $maxMB = round(self::MAX_FILE_SIZE / (1024 * 1024), 2);
            fwrite(STDERR, "ERROR: Encrypted file ({$sizeMB} MB) exceeds maximum supported single-pass backup payload size ({$maxMB} MB).\n");
            return 1;
        }

        $passphrase = self::readPassphrase($passphraseFile);
        if ($passphrase === null) {
            return 1;
        }

        $tempOutput = null;
        try {
            $data = file_get_contents($inputFile);
            $minHeaderLen = strlen(self::MAGIC) + self::SALT_LEN + 4 + self::IV_LEN + self::TAG_LEN;
            if ($data === false || strlen($data) < $minHeaderLen) {
                fwrite(STDERR, "ERROR: Encrypted file is truncated or invalid (< {$minHeaderLen} bytes)\n");
                return 1;
            }

            $offset = 0;
            $magic = substr($data, $offset, strlen(self::MAGIC));
            $offset += strlen(self::MAGIC);
            if ($magic !== self::MAGIC) {
                fwrite(STDERR, "ERROR: Magic header mismatch. Expected " . self::MAGIC . ", got: " . bin2hex($magic) . "\n");
                return 1;
            }

            $salt = substr($data, $offset, self::SALT_LEN);
            $offset += self::SALT_LEN;

            $iterArr = unpack('Niter', substr($data, $offset, 4));
            $offset += 4;
            $iterations = $iterArr['iter'] ?? self::ITERATIONS;

            $iv = substr($data, $offset, self::IV_LEN);
            $offset += self::IV_LEN;

            $tag = substr($data, $offset, self::TAG_LEN);
            $offset += self::TAG_LEN;

            $ciphertext = substr($data, $offset);

            $key = hash_pbkdf2('sha256', $passphrase, $salt, $iterations, 32, true);

            // Fail-closed AEAD decryption: openssl_decrypt validates $tag against $ciphertext + $aad before returning data
            $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, self::MAGIC);
            if ($plaintext === false) {
                fwrite(STDERR, "ERROR: Authentication failed. File corrupted, tampered, or invalid passphrase.\n");
                return 1;
            }

            $tempOutput = $outputFile . '.tmp.' . bin2hex(random_bytes(6));
            $written = file_put_contents($tempOutput, $plaintext);
            if ($written === false) {
                @unlink($tempOutput);
                fwrite(STDERR, "ERROR: Failed to write restored plaintext output: {$outputFile}\n");
                return 1;
            }

            @chmod($tempOutput, 0600);
            if (!rename($tempOutput, $outputFile)) {
                @unlink($tempOutput);
                fwrite(STDERR, "ERROR: Failed to finalize restored output: {$outputFile}\n");
                return 1;
            }

            return 0;
        } catch (Throwable $e) {
            if ($tempOutput !== null && file_exists($tempOutput)) {
                @unlink($tempOutput);
            }
            fwrite(STDERR, "ERROR: Decryption failed with exception: " . $e->getMessage() . "\n");
            return 1;
        }
    }

    public static function verifyFile(string $inputFile, string $passphraseFile): int {
        self::ensureMemoryLimit();

        if (!is_file($inputFile) || !is_readable($inputFile)) {
            fwrite(STDERR, "ERROR: Input file does not exist: {$inputFile}\n");
            return 1;
        }

        $fileSize = filesize($inputFile);
        if ($fileSize > self::MAX_FILE_SIZE) {
            $sizeMB = round($fileSize / (1024 * 1024), 2);
            $maxMB = round(self::MAX_FILE_SIZE / (1024 * 1024), 2);
            fwrite(STDERR, "ERROR: Encrypted file ({$sizeMB} MB) exceeds maximum supported single-pass backup payload size ({$maxMB} MB).\n");
            return 1;
        }

        $passphrase = self::readPassphrase($passphraseFile);
        if ($passphrase === null) {
            return 1;
        }

        $data = file_get_contents($inputFile);
        $minHeaderLen = strlen(self::MAGIC) + self::SALT_LEN + 4 + self::IV_LEN + self::TAG_LEN;
        if ($data === false || strlen($data) < $minHeaderLen) {
            fwrite(STDERR, "ERROR: File is truncated (< {$minHeaderLen} bytes)\n");
            return 1;
        }

        $offset = 0;
        $magic = substr($data, $offset, strlen(self::MAGIC));
        $offset += strlen(self::MAGIC);
        if ($magic !== self::MAGIC) {
            fwrite(STDERR, "ERROR: Magic header mismatch\n");
            return 1;
        }

        $salt = substr($data, $offset, self::SALT_LEN);
        $offset += self::SALT_LEN;

        $iterArr = unpack('Niter', substr($data, $offset, 4));
        $offset += 4;
        $iterations = $iterArr['iter'] ?? self::ITERATIONS;

        $iv = substr($data, $offset, self::IV_LEN);
        $offset += self::IV_LEN;

        $tag = substr($data, $offset, self::TAG_LEN);
        $offset += self::TAG_LEN;

        $ciphertext = substr($data, $offset);

        $key = hash_pbkdf2('sha256', $passphrase, $salt, $iterations, 32, true);
        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, self::MAGIC);
        if ($plaintext === false) {
            fwrite(STDERR, "ERROR: Integrity verification failed: Tag rejected or invalid passphrase\n");
            return 1;
        }

        fwrite(STDOUT, "OK: Authentication tag verified successfully (AES-256-GCM, " . strlen($plaintext) . " bytes payload)\n");
        return 0;
    }

    private static function readPassphrase(string $passphraseFile): ?string {
        if (!is_file($passphraseFile) || !is_readable($passphraseFile)) {
            fwrite(STDERR, "ERROR: Passphrase file does not exist or is not readable: {$passphraseFile}\n");
            return null;
        }
        $passphrase = trim((string)file_get_contents($passphraseFile));
        if ($passphrase === '') {
            fwrite(STDERR, "ERROR: Passphrase file is empty: {$passphraseFile}\n");
            return null;
        }
        return $passphrase;
    }
}

// CLI entrypoint
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    $action = $argv[1] ?? '';
    if ($action === 'encrypt' && isset($argv[2], $argv[3], $argv[4])) {
        exit(BackupCrypto::encryptFile($argv[2], $argv[3], $argv[4]));
    } elseif ($action === 'decrypt' && isset($argv[2], $argv[3], $argv[4])) {
        exit(BackupCrypto::decryptFile($argv[2], $argv[3], $argv[4]));
    } elseif ($action === 'verify' && isset($argv[2], $argv[3])) {
        exit(BackupCrypto::verifyFile($argv[2], $argv[3]));
    } else {
        fwrite(STDERR, "Usage:\n");
        fwrite(STDERR, "  php backup_crypto.php encrypt <input_file> <output_file> <passphrase_file>\n");
        fwrite(STDERR, "  php backup_crypto.php decrypt <input_file> <output_file> <passphrase_file>\n");
        fwrite(STDERR, "  php backup_crypto.php verify  <input_file> <passphrase_file>\n");
        exit(2);
    }
}
