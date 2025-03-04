<?php

namespace Kwidoo\SmsVerification\Tests\Unit\Verifiers;

use Kwidoo\SmsVerification\Verifiers\Verifier;
use Kwidoo\SmsVerification\Tests\TestCase;

class VerifierTest extends TestCase
{
    /** @test */
    public function testSanitizePhoneNumber()
    {
        $verifier = new class extends Verifier {
            public function create(string $phoneNumber): void {}
            public function validate(array $credentials): bool
            {
                return true;
            }
        };

        $this->assertEquals('+1234567890', $verifier->sanitizePhoneNumber('123-456-7890'));
        $this->assertEquals('+1234567890', $verifier->sanitizePhoneNumber('+1234567890'));
        $this->assertEquals('+1234567890', $verifier->sanitizePhoneNumber('(123) 456-7890'));
    }
}
