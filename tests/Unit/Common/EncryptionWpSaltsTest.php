<?php

declare(strict_types=1);

namespace Concordance\Tests\Unit\Common;

use Concordance\Common\Encryption;

/*
 * Covers the no-key constructor path, which derives the key from the WordPress
 * AUTH_KEY / SECURE_AUTH_KEY salts (defined in the test bootstrap).
 */

covers(\Concordance\Common\Encryption::class);

it('round-trips with a key derived from the WordPress salts', function () {
    $enc = new Encryption(); // no explicit key → derives from AUTH_KEY salts

    $encrypted = $enc->encrypt('secret-value');
    expect($encrypted)->toStartWith('$concordance$')
        ->and($enc->decrypt($encrypted))->toBe('secret-value');
});

it('lets two salt-derived instances agree', function () {
    // Both derive the same key from the process's salts, so they interoperate.
    $a = new Encryption();
    $b = new Encryption();
    expect($b->decrypt($a->encrypt('shared')))->toBe('shared');
});
