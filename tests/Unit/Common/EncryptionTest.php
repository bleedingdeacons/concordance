<?php

declare(strict_types=1);

namespace Concordance\Tests\Unit\Common;

use Concordance\Common\Encryption;

/*
 * Tests for the Encryption class.
 *
 * The constructor accepts an explicit key, so these tests run
 * without WordPress constants (AUTH_KEY / SECURE_AUTH_KEY).
 */

const TEST_KEY = 'test-encryption-key-for-unit-tests';

function keyedEncryption(): Encryption
{
    return new Encryption(TEST_KEY);
}

// ── Round-trip ──────────────────────────────────────────────────
it('decrypts what it encrypted back to the original plaintext', function () {
    $enc = keyedEncryption();
    $plaintext = 'my-secret-api-key-12345';

    $encrypted = $enc->encrypt($plaintext);
    $decrypted = $enc->decrypt($encrypted);

    expect($decrypted)->toBe($plaintext);
});

it('round-trips unicode content', function () {
    $enc = keyedEncryption();
    $plaintext = 'café-naïve-résumé-日本語';

    $decrypted = $enc->decrypt($enc->encrypt($plaintext));

    expect($decrypted)->toBe($plaintext);
});

it('round-trips long values', function () {
    $enc = keyedEncryption();
    $plaintext = str_repeat('a', 10000);

    $decrypted = $enc->decrypt($enc->encrypt($plaintext));

    expect($decrypted)->toBe($plaintext);
});

// ── Empty string ────────────────────────────────────────────────
it('encrypts an empty input to an empty string', function () {
    $enc = keyedEncryption();

    expect($enc->encrypt(''))->toBe('');
});

it('decrypts an empty input to an empty string', function () {
    $enc = keyedEncryption();

    expect($enc->decrypt(''))->toBe('');
});

// ── Encrypted prefix ────────────────────────────────────────────
it('prefixes an encrypted value with $concordance$', function () {
    $enc = keyedEncryption();

    $encrypted = $enc->encrypt('hello');

    expect($encrypted)->toStartWith('$concordance$');
});

it('does not leave the plaintext in the encrypted value', function () {
    $enc = keyedEncryption();
    $plaintext = 'sensitive-data';

    $encrypted = $enc->encrypt($plaintext);

    expect($encrypted)->not->toBe($plaintext)
        ->not->toContain($plaintext);
});

// ── isEncrypted ─────────────────────────────────────────────────
it('recognises an encrypted value as encrypted', function () {
    $enc = keyedEncryption();

    $encrypted = $enc->encrypt('test');

    expect($enc->isEncrypted($encrypted))->toBeTrue();
});

it('does not recognise a plain value as encrypted', function () {
    $enc = keyedEncryption();

    expect($enc->isEncrypted('plain-text-api-key'))->toBeFalse()
        ->and($enc->isEncrypted(''))->toBeFalse();
});

// ── Uniqueness (IV randomness) ──────────────────────────────────
it('produces different ciphertext for the same plaintext twice', function () {
    $enc = keyedEncryption();
    $plaintext = 'same-input';

    $a = $enc->encrypt($plaintext);
    $b = $enc->encrypt($plaintext);

    expect($a)->not->toBe($b, 'Each encryption should use a random IV');
});

// ── Tampered / malformed data ───────────────────────────────────
it('decrypts a value without the prefix to an empty string', function () {
    $enc = keyedEncryption();

    expect($enc->decrypt('not-encrypted-at-all'))->toBe('');
});

it('decrypts tampered ciphertext to an empty string', function () {
    $enc = keyedEncryption();

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

    expect($enc->decrypt($tampered))->toBe('');
});

it('decrypts truncated ciphertext to an empty string', function () {
    $enc = keyedEncryption();

    // Prefix + too-short payload (less than IV + tag length)
    expect($enc->decrypt('$concordance$' . base64_encode('short')))->toBe('');
});

it('decrypts invalid base64 to an empty string', function () {
    $enc = keyedEncryption();

    expect($enc->decrypt('$concordance$!!!not-base64!!!'))->toBe('');
});

// ── Different keys ──────────────────────────────────────────────
it('decrypts to an empty string under a different key', function () {
    $enc1 = new Encryption('key-one');
    $enc2 = new Encryption('key-two');

    $encrypted = $enc1->encrypt('secret');
    $decrypted = $enc2->decrypt($encrypted);

    expect($decrypted)->toBe('');
});
