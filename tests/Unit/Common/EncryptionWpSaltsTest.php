<?php

declare(strict_types=1);

namespace Concordance\Tests\Unit\Common;

use Concordance\Common\Encryption;
use PHPUnit\Framework\TestCase;

/**
 * Covers the no-key constructor path, which derives the key from the WordPress
 * AUTH_KEY / SECURE_AUTH_KEY salts (defined in the test bootstrap).
 *
 * @covers \Concordance\Common\Encryption
 */
class EncryptionWpSaltsTest extends TestCase
{
    public function testRoundTripWithWordPressSaltKey(): void
    {
        $enc = new Encryption(); // no explicit key → derives from AUTH_KEY salts

        $encrypted = $enc->encrypt('secret-value');
        $this->assertStringStartsWith('$concordance$', $encrypted);
        $this->assertSame('secret-value', $enc->decrypt($encrypted));
    }

    public function testTwoSaltDerivedInstancesAgree(): void
    {
        // Both derive the same key from the process's salts, so they interoperate.
        $a = new Encryption();
        $b = new Encryption();
        $this->assertSame('shared', $b->decrypt($a->encrypt('shared')));
    }
}
