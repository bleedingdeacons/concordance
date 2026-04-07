<?php

declare(strict_types=1);

namespace Concordance\Common;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Encryption
 *
 * Provides symmetric encryption for sensitive option values (e.g. API keys)
 * stored in wp_options. Uses AES-256-GCM with a key derived from WordPress
 * authentication salts so the ciphertext is worthless without access to
 * wp-config.php.
 *
 * ── Threat model ──
 * Protects against:
 *   • SQL-injection or DB dump exposing raw option values.
 *   • Read-only filesystem access to a database backup.
 * Does NOT protect against:
 *   • Full server compromise (attacker has wp-config.php).
 *   • An attacker who can execute arbitrary PHP in the WP context.
 *
 * ── Graceful degradation ──
 * If the OpenSSL extension is unavailable the class falls back to
 * Base64-encoding. This is NOT encryption but still prevents the key
 * from appearing in plain sight during casual inspection and keeps the
 * plugin functional on minimal hosting environments.
 *
 * @since 1.1.0
 */
final class Encryption
{
    /** Cipher used when OpenSSL is available. */
    private const CIPHER = 'aes-256-gcm';

    /** Prefix prepended to every ciphertext so we can detect already-encrypted values. */
    private const ENCRYPTED_PREFIX = '$concordance$';

    /** Tag length for GCM mode (bytes). */
    private const TAG_LENGTH = 16;

    /** @var string Derived encryption key (binary, 32 bytes). */
    private string $key;

    /** @var bool Whether real encryption is available. */
    private bool $canEncrypt;

    /**
     * @param string|null $key Raw key material. When null the WordPress
     *                         AUTH_KEY + SECURE_AUTH_KEY salts are used.
     */
    public function __construct(?string $key = null)
    {
        $raw = $key ?? $this->getWordPressSalts();

        // Derive a fixed-length 256-bit key via SHA-256.
        $this->key = hash('sha256', $raw, true);

        $this->canEncrypt = extension_loaded('openssl')
            && in_array(self::CIPHER, openssl_get_cipher_methods(true), true);
    }

    /**
     * Encrypt a plaintext string.
     *
     * @param string $plaintext The value to encrypt.
     * @return string           Base64-encoded ciphertext prefixed with self::ENCRYPTED_PREFIX.
     */
    public function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }

        if (!$this->canEncrypt) {
            // Fallback: obfuscate with Base64 so the value is not human-readable.
            return self::ENCRYPTED_PREFIX . base64_encode($plaintext);
        }

        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $iv       = openssl_random_pseudo_bytes($ivLength);
        $tag      = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH
        );

        if ($ciphertext === false) {
            // If encryption unexpectedly fails, fall back to base64 rather than
            // storing the key in plaintext.
            return self::ENCRYPTED_PREFIX . base64_encode($plaintext);
        }

        // Pack: IV + tag + ciphertext → Base64
        return self::ENCRYPTED_PREFIX . base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Decrypt a value previously produced by encrypt().
     *
     * @param string $stored The stored (encrypted) value.
     * @return string        The original plaintext, or empty string if the value
     *                       is missing, malformed, or not encrypted.
     */
    public function decrypt(string $stored): string
    {
        if ($stored === '') {
            return '';
        }

        // Only values carrying our prefix are valid. Unencrypted legacy values
        // are treated as invalid — the admin must re-enter the key.
        if (!str_starts_with($stored, self::ENCRYPTED_PREFIX)) {
            return '';
        }

        $payload = substr($stored, strlen(self::ENCRYPTED_PREFIX));
        $raw     = base64_decode($payload, true);

        if ($raw === false) {
            return '';
        }

        if (!$this->canEncrypt) {
            // Fallback mode used Base64 only.
            return $raw;
        }

        $ivLength = openssl_cipher_iv_length(self::CIPHER);

        // Payload must be at least IV + tag.
        if (strlen($raw) < $ivLength + self::TAG_LENGTH) {
            return '';
        }

        $iv         = substr($raw, 0, $ivLength);
        $tag        = substr($raw, $ivLength, self::TAG_LENGTH);
        $ciphertext = substr($raw, $ivLength + self::TAG_LENGTH);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        // openssl_decrypt returns false on tampered/corrupted data.
        return $plaintext !== false ? $plaintext : '';
    }

    /**
     * Check whether a stored value is already encrypted.
     *
     * @param string $value
     * @return bool
     */
    public function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::ENCRYPTED_PREFIX);
    }

    /**
     * Concatenate WordPress authentication salts to form the raw key material.
     *
     * @return string
     */
    private function getWordPressSalts(): string
    {
        $salts = '';

        if (defined('AUTH_KEY')) {
            $salts .= AUTH_KEY;
        }
        if (defined('SECURE_AUTH_KEY')) {
            $salts .= SECURE_AUTH_KEY;
        }

        // Ultimate fallback — still better than a hard-coded key, but
        // admins should really set proper salts.
        if ($salts === '') {
            $salts = 'concordance-default-key-please-set-wp-salts';
        }

        return $salts;
    }
}
