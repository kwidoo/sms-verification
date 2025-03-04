<?php

namespace Kwidoo\SmsVerification\Tests\Unit\Verifiers;

use Illuminate\Support\Facades\Cache;
use Kwidoo\SmsVerification\Verifiers\VonageVerifier;
use Kwidoo\SmsVerification\Exceptions\VerifierException;
use Kwidoo\SmsVerification\Tests\TestCase;
use Mockery;
use Vonage\Client;

class VonageVerifierTest extends TestCase
{
    public function testCreateThrowsExceptionWhenResponseInvalid()
    {
        // Prepare a fake Vonage client with verify2()->startVerification() returning an invalid response.
        $verify2Mock = Mockery::mock();
        $verify2Mock->shouldReceive('startVerification')
            ->once()
            ->andReturn([]); // no 'request_id'

        $vonageClientMock = Mockery::mock(Client::class);
        $vonageClientMock->shouldReceive('verify2')
            ->andReturn($verify2Mock);

        $verifier = new VonageVerifier($vonageClientMock);
        $this->expectException(VerifierException::class);
        $verifier->create('1234567890');
    }

    public function testCreateCachesRequestIdOnValidResponse()
    {
        $verify2Mock = Mockery::mock();
        $verify2Mock->shouldReceive('startVerification')
            ->once()
            ->andReturn(['request_id' => 'abc123']);

        $vonageClientMock = Mockery::mock(Client::class);
        $vonageClientMock->shouldReceive('verify2')
            ->andReturn($verify2Mock);

        // Use a unique phone number and reset cache.
        Cache::flush();
        $verifier = new VonageVerifier($vonageClientMock);
        $inputNumber = '1234567890';
        $sanitized = '+1234567890';
        $cacheKey = "vonage{$sanitized}";

        $verifier->create($inputNumber);

        $this->assertEquals('abc123', Cache::get($cacheKey));
    }

    public function testValidateThrowsExceptionWhenInsufficientCredentials()
    {
        $vonageClientMock = Mockery::mock(Client::class);
        $verifier = new VonageVerifier($vonageClientMock);

        $this->expectException(VerifierException::class);
        $verifier->validate(['+1234567890']);
    }

    public function testValidateThrowsExceptionWhenNoCachedRequestId()
    {
        Cache::flush();
        $vonageClientMock = Mockery::mock(Client::class);
        $verifier = new VonageVerifier($vonageClientMock);

        $this->expectException(VerifierException::class);
        $verifier->validate(['+1234567890', '1234']);
    }

    public function testValidateReturnsTrueOnValidVerification()
    {
        $verify2Mock = Mockery::mock();
        $verify2Mock->shouldReceive('check')
            ->once()
            ->with('abc123', '1234')
            ->andReturn(true);

        $vonageClientMock = Mockery::mock(Client::class);
        $vonageClientMock->shouldReceive('verify2')
            ->andReturn($verify2Mock);

        // First cache a request ID for the sanitized phone number.
        Cache::flush();
        $number = '1234567890';
        $sanitized = '+1234567890';
        $cacheKey = "vonage{$sanitized}";
        Cache::put($cacheKey, 'abc123', now()->addMinutes(5));

        $verifier = new VonageVerifier($vonageClientMock);
        $result = $verifier->validate([$number, '1234']);
        $this->assertTrue($result);
    }
}
