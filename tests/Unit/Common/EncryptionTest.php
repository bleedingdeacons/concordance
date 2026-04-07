<?php

declare(strict_types=1);

namespace Concordance\Tests\Unit\Common;

use Concordance\Common\Encryption;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Encryption class.
 *
 * The constructor accepts an explicit key, so these tests run
 * without WordPress constants (AUTH_KEY / SECURE_AUTH_KEY).
 */
class EncryptionTest extends TestCase
{
    private const TEST_KEY = 'test-encryption-key-for-unit-tests';

    private function createEncryption(): Encryption
    {
        return new Encryption(self::TEST_KEY);
    }

    // ── Round-trip ──────────────────────────────────────────────────

    /** @test */
    public function encrypt_then_decrypt_returns_original_plaintext(): void
    {
        $enc = $this->createEncryption();
        $plaintext = 'my-secret-api-key-12345';

        $encrypted = $enc->encrypt($plaintext);
        $decrypted = $enc->decrypt($encrypted);

        $this->assertSame($plaintext, $decrypted);
    }

    /** @test */
    public function round_trip_works_for_unicode_content(): void
    {
        $enc = $this->createEncryption();
        $plaintext = 'café-naïve-résumé-日本語';

        $decrypted = $enc->decrypt($enc->encrypt($plaintext));

        $this->assertSame($plaintext, $decrypted);
    }

    /** @test */
    public function round_trip_works_for_long_values(): void
    {
        $enc = $this->createEncryption();
        $plaintext = str_repeat('a', 10000);

        $decrypted = $enc->decrypt($enc->encrypt($plaintext));

        $this->assertSame($plaintext, $decrypted);
    }

    // ── Empty string ────────────────────────────────────────────────

    /** @test */
    public function encrypt_returns_empty_string_for_empty_input(): void
    {
        $enc = $this->createEncryption();

        $this->assertSame('', $enc->encrypt(''));
    }

    /** @test */
    public function decrypt_returns_empty_string_for_empty_input(): void
    {
        $enc = $this->createEncryption();

        $this->assertSame('', $enc->decrypt(''));
    }

    // ── Encrypted prefix ────────────────────────────────────────────

    /** @test */
    public function encrypted_value_starts_with_concordance_prefix(): void
    {
        $enc = $this->createEncryption();

        $encrypted = $enc->encrypt('hello');

        $this->assertStringStartsWith('$concordance$', $encrypted);
    }

    /** @test */
    public function encrypted_value_is_not_the_same_as_plaintext(): void
    {
        $enc = $this->createEncryption();
        $plaintext = 'sensitive-data';

        $encrypted = $enc->encrypt($plaintext);

        $this->assertNotSame($plaintext, $encrypted);
        $this->assertStringNotContainsString($plaintext, $encrypted);
    }

    // ── isEncrypted ─────────────────────────────────────────────────

    /** @test */
    public function isEncrypted_returns_true_for_encrypted_values(): void
    {
        $enc = $this->createEncryption();

        $encrypted = $enc->encrypt('test');

        $this->assertTrue($enc->isEncrypted($encrypted));
    }

    /** @test */
    public function isEncrypted_returns_false_for_plain_values(): void
    {
        $enc = $this->createEncryption();

        $this->assertFalse($enc->isEncrypted('plain-text-api-key'));
        $this->assertFalse($enc->isEncrypted(''));
    }

    // ── Uniqueness (IV randomness) ──────────────────────────────────

    /** @test */
    public function encrypting_same_plaintext_twice_produces_different_ciphertext(): void
    {
        $enc = $this->createEncryption();
        $plaintext = 'same-input';

        $a = $enc->encrypt($plaintext);
        $b = $enc->encrypt($plaintext);

        $this->assertNotSame($a, $b, 'Each encryption should use a random IV');
    }

    // ── Tampered / malformed data ───────────────────────────────────

    /** @test */
    public function decrypt_returns_empty_for_value_without_prefix(): void
    {
        $enc = $this->createEncryption();

        $this->assertSame('', $enc->decrypt('not-encrypted-at-all'));
    }

    /** @test */
    public function decrypt_returns_empty_for_tampered_ciphertext(): void
    {
        $enc = $this->createEncryption();

        $encrypted = $enc->encrypt('secret');

        // Flip a character in the base64 payload
        $tampered = substr($encrypted, 0, 20) . 'X' . substr($encrypted, 21);

        $this->assertSame('', $enc->decrypt($tampered));
    }

    /** @test */
    public function decrypt_returns_empty_for_truncated_ciphertext(): void
    {
        $enc = $this->createEncryption();

        // Prefix + too-short payload (less than IV + tag length)
        $this->assertSame('', $enc->decrypt('$concordance$' . base64_encode('short')));
    }

    /** @test */
    public function decrypt_returns_empty_for_invalid_base64(): void
    {
        $enc = $this->createEncryption();

        $this->assertSame('', $enc->decrypt('$concordance$!!!not-base64!!!'));
    }

    // ── Different keys ──────────────────────────────────────────────

    /** @test */
    public function decrypt_with_different_key_returns_empty(): void
    {
        $enc1 = new Encryption('key-one');
        $enc2 = new Encryption('key-two');

        $encrypted = $enc1->encrypt('secret');
        $decrypted = $enc2->decrypt($encrypted);

        $this->assertSame('', $decrypted);
    }
}
