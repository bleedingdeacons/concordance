<?php

declare(strict_types=1);

namespace Concordance\Tests\Unit\Common;

use PHPUnit\Framework\Attributes\Test;
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
    #[Test]
    public function encrypt_then_decrypt_returns_original_plaintext(): void
    {
        $enc = $this->createEncryption();
        $plaintext = 'my-secret-api-key-12345';

        $encrypted = $enc->encrypt($plaintext);
        $decrypted = $enc->decrypt($encrypted);

        $this->assertSame($plaintext, $decrypted);
    }

    #[Test]
    public function round_trip_works_for_unicode_content(): void
    {
        $enc = $this->createEncryption();
        $plaintext = 'café-naïve-résumé-日本語';

        $decrypted = $enc->decrypt($enc->encrypt($plaintext));

        $this->assertSame($plaintext, $decrypted);
    }

    #[Test]
    public function round_trip_works_for_long_values(): void
    {
        $enc = $this->createEncryption();
        $plaintext = str_repeat('a', 10000);

        $decrypted = $enc->decrypt($enc->encrypt($plaintext));

        $this->assertSame($plaintext, $decrypted);
    }

    // ── Empty string ────────────────────────────────────────────────
    #[Test]
    public function encrypt_returns_empty_string_for_empty_input(): void
    {
        $enc = $this->createEncryption();

        $this->assertSame('', $enc->encrypt(''));
    }

    #[Test]
    public function decrypt_returns_empty_string_for_empty_input(): void
    {
        $enc = $this->createEncryption();

        $this->assertSame('', $enc->decrypt(''));
    }

    // ── Encrypted prefix ────────────────────────────────────────────
    #[Test]
    public function encrypted_value_starts_with_concordance_prefix(): void
    {
        $enc = $this->createEncryption();

        $encrypted = $enc->encrypt('hello');

        $this->assertStringStartsWith('$concordance$', $encrypted);
    }

    #[Test]
    public function encrypted_value_is_not_the_same_as_plaintext(): void
    {
        $enc = $this->createEncryption();
        $plaintext = 'sensitive-data';

        $encrypted = $enc->encrypt($plaintext);

        $this->assertNotSame($plaintext, $encrypted);
        $this->assertStringNotContainsString($plaintext, $encrypted);
    }

    // ── isEncrypted ─────────────────────────────────────────────────
    #[Test]
    public function isEncrypted_returns_true_for_encrypted_values(): void
    {
        $enc = $this->createEncryption();

        $encrypted = $enc->encrypt('test');

        $this->assertTrue($enc->isEncrypted($encrypted));
    }

    #[Test]
    public function isEncrypted_returns_false_for_plain_values(): void
    {
        $enc = $this->createEncryption();

        $this->assertFalse($enc->isEncrypted('plain-text-api-key'));
        $this->assertFalse($enc->isEncrypted(''));
    }

    // ── Uniqueness (IV randomness) ──────────────────────────────────
    #[Test]
    public function encrypting_same_plaintext_twice_produces_different_ciphertext(): void
    {
        $enc = $this->createEncryption();
        $plaintext = 'same-input';

        $a = $enc->encrypt($plaintext);
        $b = $enc->encrypt($plaintext);

        $this->assertNotSame($a, $b, 'Each encryption should use a random IV');
    }

    // ── Tampered / malformed data ───────────────────────────────────
    #[Test]
    public function decrypt_returns_empty_for_value_without_prefix(): void
    {
        $enc = $this->createEncryption();

        $this->assertSame('', $enc->decrypt('not-encrypted-at-all'));
    }

    #[Test]
    public function decrypt_returns_empty_for_tampered_ciphertext(): void
    {
        $enc = $this->createEncryption();

        $encrypted = $enc->encrypt('secret');

        // Flip a character in the base64 payload. The replacement is chosen
        // against the original rather than hardcoded: index 20 falls inside
        // the base64 of the random IV, so a fixed 'X' leaves the payload
        // untouched roughly one run in sixty-four - base64 has a 64-character
        // alphabet - and the assertion then fails because undamaged data
        // decrypts perfectly well. Same idiom as reach's SessionCookieTest.
        $tampered = substr($encrypted, 0, 20)
            . ($encrypted[20] === 'X' ? 'Y' : 'X')
            . substr($encrypted, 21);

        $this->assertSame('', $enc->decrypt($tampered));
    }

    #[Test]
    public function decrypt_returns_empty_for_truncated_ciphertext(): void
    {
        $enc = $this->createEncryption();

        // Prefix + too-short payload (less than IV + tag length)
        $this->assertSame('', $enc->decrypt('$concordance$' . base64_encode('short')));
    }

    #[Test]
    public function decrypt_returns_empty_for_invalid_base64(): void
    {
        $enc = $this->createEncryption();

        $this->assertSame('', $enc->decrypt('$concordance$!!!not-base64!!!'));
    }

    // ── Different keys ──────────────────────────────────────────────
    #[Test]
    public function decrypt_with_different_key_returns_empty(): void
    {
        $enc1 = new Encryption('key-one');
        $enc2 = new Encryption('key-two');

        $encrypted = $enc1->encrypt('secret');
        $decrypted = $enc2->decrypt($encrypted);

        $this->assertSame('', $decrypted);
    }
}
