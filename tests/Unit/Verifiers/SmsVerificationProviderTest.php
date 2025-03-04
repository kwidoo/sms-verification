<?php

namespace Kwidoo\SmsVerification\Tests\Unit;

use Mockery;
use Kwidoo\SmsVerification\Tests\TestCase;
use Twilio\Rest\Client as TwilioClient;
use Vonage\Client as VonageClient;
use Kwidoo\SmsVerification\SmsVerificationProvider;

class SmsVerificationProviderTest extends TestCase
{
    /** @test */
    public function testServiceBindings()
    {
        $provider = new SmsVerificationProvider($this->app);
        $provider->register();

        $this->assertInstanceOf(TwilioClient::class, app(TwilioClient::class));
        $this->assertInstanceOf(VonageClient::class, app(VonageClient::class));
    }
}
