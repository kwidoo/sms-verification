<?php

namespace Kwidoo\SmsVerification\Tests\Unit;

use Kwidoo\SmsVerification\VerifierFactory;
use Kwidoo\SmsVerification\Exceptions\VerifierException;
use Kwidoo\SmsVerification\Verifiers\TwilioVerifier;
use Kwidoo\SmsVerification\Verifiers\VonageVerifier;
use Kwidoo\SmsVerification\Verifiers\RoundRobinVerifier;
use Illuminate\Contracts\Container\Container;
use Kwidoo\SmsVerification\Tests\TestCase;
use Mockery;

class VerifierFactoryTest extends TestCase
{

    public function testMakeTwilioProvider()
    {
        // Create a mock for TwilioClient
        $twilioClientMock = Mockery::mock('Twilio\Rest\Client');
        // Create a fake container that returns our Twilio client when asked
        $containerMock = Mockery::mock(Container::class);
        $containerMock->shouldReceive('make')
            ->with('Twilio\Rest\Client')
            ->andReturn($twilioClientMock);

        // Set default provider to "twilio" via config
        config(['sms-verification.default' => 'twilio']);

        $factory = new VerifierFactory($containerMock);
        $verifier = $factory->make();

        $this->assertInstanceOf(TwilioVerifier::class, $verifier);
    }

    public function testMakeVonageProvider()
    {
        $vonageClientMock = Mockery::mock('Vonage\Client');
        $containerMock = Mockery::mock(Container::class);
        $containerMock->shouldReceive('make')
            ->with('Vonage\Client')
            ->andReturn($vonageClientMock);

        $factory = new VerifierFactory($containerMock);
        $verifier = $factory->make('vonage');

        $this->assertInstanceOf(VonageVerifier::class, $verifier);
    }

    public function testMakeUnsupportedProviderThrowsException()
    {
        $containerMock = Mockery::mock(Container::class);
        $factory = new VerifierFactory($containerMock);

        $this->expectException(VerifierException::class);
        $factory->make('unsupported');
    }

    public function testMakeRoundRobinProvider()
    {
        // Create mocks for Twilio and Vonage clients.
        $twilioClientMock = Mockery::mock('Twilio\Rest\Client');
        $vonageClientMock = Mockery::mock('Vonage\Client');

        $containerMock = Mockery::mock(Container::class);
        $containerMock->shouldReceive('make')
            ->with('Twilio\Rest\Client')
            ->andReturn($twilioClientMock);
        $containerMock->shouldReceive('make')
            ->with('Vonage\Client')
            ->andReturn($vonageClientMock);

        // Set default provider to round robin and specify providers.
        config(['sms-verification.default' => 'roundRobin']);
        config(['sms-verification.round_robin.providers' => ['twilio', 'vonage']]);

        $factory = new VerifierFactory($containerMock);
        $verifier = $factory->make();

        $this->assertInstanceOf(RoundRobinVerifier::class, $verifier);
    }

    public function testMakeRoundRobinWithoutProvidersThrowsException()
    {
        $containerMock = Mockery::mock(Container::class);
        config(['sms-verification.default' => 'roundRobin']);
        config(['sms-verification.round_robin.providers' => []]);

        $factory = new VerifierFactory($containerMock);
        $this->expectException(VerifierException::class);
        $factory->make();
    }
}
