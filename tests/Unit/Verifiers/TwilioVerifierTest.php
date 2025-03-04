<?php

namespace Kwidoo\SmsVerification\Tests\Unit\Verifiers;

use Kwidoo\SmsVerification\Verifiers\TwilioVerifier;
use Kwidoo\SmsVerification\Exceptions\VerifierException;
use Kwidoo\SmsVerification\Tests\TestCase;
use Mockery;

class TwilioVerifierTest extends TestCase
{
    public function testCreateThrowsExceptionWhenNoVerifySidConfigured()
    {
        /** @var \Twilio\Rest\Client */
        $clientMock = Mockery::mock('Twilio\Rest\Client');
        $clientMock->shouldReceive('request');

        $verifier = new TwilioVerifier($clientMock);
        config(['sms-verification.twilio.verify_sid' => null]);

        $this->expectException(VerifierException::class);
        $verifier->create('1234567890');
    }

    public function testValidateThrowsExceptionWhenInsufficientCredentials()
    {
        /** @var \Twilio\Rest\Client */
        $clientMock = Mockery::mock('Twilio\Rest\Client');
        $clientMock->shouldReceive('request');

        $verifier = new TwilioVerifier($clientMock);

        $this->expectException(VerifierException::class);
        $verifier->validate(['+1234567890']); // missing code
    }

    public function testValidateThrowsExceptionOnInvalidVerification()
    {
        $verifySid = 'test_verify_sid';
        config(['sms-verification.twilio.verify_sid' => $verifySid]);
        $number = '+1234567890';
        $credentials = [$number, '1234'];

        // Create a verification stub with valid=true.
        $verificationStub = new \stdClass();
        $verificationStub->valid = false;

        $responseMock = Mockery::mock('Twilio\Http\Response');
        $responseMock->shouldReceive('getStatusCode')->andReturn(200);
        $responseMock->shouldReceive('getContent')->andReturn((array)$verificationStub);
        // Create a verification stub with valid=false and stub getStatusCode.
        $verificationStub = Mockery::mock();
        $verificationStub->shouldReceive('getStatusCode')->andReturn(200);
        $verificationStub->valid = false;

        /** @var \Twilio\Rest\Client */
        $clientMock = Mockery::mock('Twilio\Rest\Client');
        $clientMock->shouldReceive('request')->andReturn($responseMock);

        $verifier = new TwilioVerifier($clientMock);
        $this->expectException(VerifierException::class);
        $verifier->validate($credentials);
    }

    public function testValidateReturnsTrueOnValidVerification()
    {
        $verifySid = 'test_verify_sid';
        config(['sms-verification.twilio.verify_sid' => $verifySid]);
        $number = '+1234567890';
        $credentials = [$number, '1234'];

        // Create a verification stub with valid=true.
        $verificationStub = new \stdClass();
        $verificationStub->valid = true;

        $responseMock = Mockery::mock('Twilio\Http\Response');
        $responseMock->shouldReceive('getStatusCode')->andReturn(200);
        $responseMock->shouldReceive('getContent')->andReturn((array)$verificationStub);

        /** @var \Twilio\Rest\Client */
        $clientMock = Mockery::mock('Twilio\Rest\Client');
        $clientMock->shouldReceive('request')->andReturn($responseMock);

        $verifier = new TwilioVerifier($clientMock);
        $result = $verifier->validate($credentials);

        $this->assertTrue($result);
    }
}
