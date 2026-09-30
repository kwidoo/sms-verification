<?php

namespace Kwidoo\SmsVerification\Tests\Unit;

use DateTimeImmutable;
use InvalidArgumentException;
use Kwidoo\SmsVerification\Challenge\Challenge;
use Kwidoo\SmsVerification\Challenge\OtpGenerator;
use Kwidoo\SmsVerification\Challenge\OtpHasher;
use Kwidoo\SmsVerification\Exceptions\VerifierException;
use PHPUnit\Framework\TestCase;

class ChallengeTest extends TestCase
{
    public function testRoundTripsThroughArray(): void
    {
        $challenge = new Challenge('+37120000000', 'REF', ['a' => 1], new DateTimeImmutable('2030-01-01T00:00:00+00:00'));

        $this->assertEquals($challenge, Challenge::fromArray($challenge->toArray()));
    }

    public function testChallengeWithoutExpiryNeverExpires(): void
    {
        $this->assertFalse((new Challenge('+37120000000'))->isExpired());
    }

    public function testFromArrayRejectsMissingRecipient(): void
    {
        $this->expectException(VerifierException::class);

        Challenge::fromArray(['reference' => 'REF']);
    }

    public function testFromArrayRejectsBadExpiry(): void
    {
        $this->expectException(VerifierException::class);

        Challenge::fromArray(['recipient' => '+1', 'expires_at' => 'not a date']);
    }

    public function testGeneratorProducesDigitsOfRequestedLength(): void
    {
        $generator = new OtpGenerator();

        foreach ([4, 6, 8] as $length) {
            $this->assertMatchesRegularExpression('/^\d{'.$length.'}$/', $generator->generate($length));
        }
    }

    public function testGeneratorRejectsShortCodes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new OtpGenerator())->generate(3);
    }

    public function testHasherAcceptsLaravelBase64Keys(): void
    {
        $hasher = OtpHasher::fromKey('base64:'.base64_encode(random_bytes(32)));
        $state = $hasher->seal('123456', '+1', 'REF', null);

        $this->assertTrue($hasher->matches($state, '123456', '+1', 'REF', null));
        $this->assertFalse($hasher->matches($state, '123457', '+1', 'REF', null));
        $this->assertNull(OtpHasher::fromKey(''));
    }

    public function testHasherRejectsUnknownStateVersion(): void
    {
        $hasher = new OtpHasher(str_repeat('k', 32));
        $state = ['v' => 99] + $hasher->seal('123456', '+1', 'REF', null);

        $this->assertFalse($hasher->matches($state, '123456', '+1', 'REF', null));
    }

    public function testHasherRejectsShortKey(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new OtpHasher('short');
    }
}
