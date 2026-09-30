<?php

namespace Kwidoo\SmsVerification\Tests\Unit;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Kwidoo\SmsVerification\Challenge\Challenge;
use Kwidoo\SmsVerification\Challenge\ChallengeDrivers;
use Kwidoo\SmsVerification\Challenge\ChallengeRuntime;
use Kwidoo\SmsVerification\Contracts\ChallengeDriver;
use Kwidoo\SmsVerification\Contracts\ChallengeVerifierInterface;
use Kwidoo\SmsVerification\Exceptions\ConfigurationException;
use Kwidoo\SmsVerification\Verifiers\TelesignSmsVerifyVerifier;
use Kwidoo\SmsVerification\Verifiers\TelesignVerifier;
use Kwidoo\SmsVerification\Verifiers\TelesignVerifyVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

class ChallengeDriversTest extends TestCase
{
    private const CONFIG = ['customer_id' => 'FFFFFFFF-EEEE-DDDD-1234-AB1234567890', 'api_key' => 'a2V5'];

    /** @var list<RequestInterface> */
    private array $sent = [];

    private MockHandler $mock;

    private function runtime(?string $codeKey = null): ChallengeRuntime
    {
        $this->mock = new MockHandler();

        return new ChallengeRuntime(
            httpHandler: function (RequestInterface $request, array $options) {
                $this->sent[] = $request;

                return ($this->mock)($request, $options);
            },
            codeKey: $codeKey ?? str_repeat('k', 32),
            timeout: 5.0,
        );
    }

    public function testDefaultsRegisterTheTelesignDrivers(): void
    {
        $drivers = ChallengeDrivers::defaults();

        $this->assertSame(['telesign', 'telesign_verify', 'telesign_sms_verify'], array_slice($drivers->names(), 0, 3));
        $this->assertSame('telesign', $drivers->defaultName());
        $this->assertTrue($drivers->has(' Telesign_Verify '));
    }

    public static function drivers(): array
    {
        return [
            'messaging' => ['telesign', TelesignVerifier::class, 'https://rest-api.telesign.com/v1/messaging'],
            'verify' => ['telesign_verify', TelesignVerifyVerifier::class, 'https://verify.telesign.com/verification'],
            'sms verify' => ['telesign_sms_verify', TelesignSmsVerifyVerifier::class, 'https://rest-ww.telesign.com/v1/verify/sms'],
        ];
    }

    #[DataProvider('drivers')]
    public function testEachDriverSendsThroughTheHostHandlerToItsProductionEndpoint(string $name, string $class, string $uri): void
    {
        $runtime = $this->runtime();
        $verifier = ChallengeDrivers::defaults()->make($name, self::CONFIG, $runtime);
        $this->mock->append(new Response(200, [], json_encode(['reference_id' => 'REF', 'status' => ['code' => 290]])));

        $this->assertInstanceOf($class, $verifier);

        $challenge = $verifier->dispatch('+37120000000');

        $this->assertSame('REF', $challenge->reference);
        $this->assertCount(1, $this->sent);
        $this->assertSame($uri, (string) $this->sent[0]->getUri());
    }

    #[DataProvider('drivers')]
    public function testEachDriverHonoursAConfiguredEndpoint(string $name): void
    {
        $runtime = $this->runtime();
        $verifier = ChallengeDrivers::defaults()->make($name, [...self::CONFIG, 'url' => 'https://sandbox.example.test/'], $runtime);
        $this->mock->append(new Response(200, [], json_encode(['reference_id' => 'REF'])));

        $verifier->dispatch('+37120000000');

        $this->assertSame('sandbox.example.test', $this->sent[0]->getUri()->getHost());
    }

    #[DataProvider('drivers')]
    public function testMissingCredentialsAreAConfigurationError(string $name): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('api_key');

        ChallengeDrivers::defaults()->make($name, ['customer_id' => 'x', 'api_key' => ' '], $this->runtime());
    }

    public function testUnknownDriverIsAConfigurationError(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('telesign, telesign_verify, telesign_sms_verify, twilio');

        ChallengeDrivers::defaults()->make('pigeon', self::CONFIG);
    }

    public function testMessagingNeedsACodeKey(): void
    {
        $this->expectException(ConfigurationException::class);

        ChallengeDrivers::defaults()->make('telesign', self::CONFIG, new ChallengeRuntime());
    }

    public function testMessagingRejectsAWeakCodeKey(): void
    {
        $this->expectException(ConfigurationException::class);

        ChallengeDrivers::defaults()->make('telesign', self::CONFIG, $this->runtime('short'));
    }

    public function testMessagingDriverAppliesItsOptions(): void
    {
        $runtime = $this->runtime();
        $verifier = ChallengeDrivers::defaults()->make('telesign', [...self::CONFIG, 'message' => 'ACME :code', 'code_length' => '4'], $runtime);
        $this->mock->append(new Response(200, [], json_encode(['reference_id' => 'REF'])));

        $verifier->dispatch('+37120000000');

        parse_str((string) $this->sent[0]->getBody(), $fields);
        $this->assertMatchesRegularExpression('/^ACME \d{4}$/', $fields['message']);
    }

    public function testOptionsAreMergedForOneConfigurationForm(): void
    {
        $options = array_column(ChallengeDrivers::defaults()->options(), null, 'key');

        // Required by the Telesign drivers, not by every driver: not required overall.
        $this->assertFalse($options['customer_id']['required']);
        $this->assertStringContainsString('telesign: Customer ID', $options['customer_id']['description']);

        $this->assertTrue($options['api_key']['sensitive']);
        $this->assertArrayNotHasKey('default', $options['api_key']);
        $this->assertStringContainsString('seven: API key', $options['api_key']['description']);

        // Different defaults per driver: none is published.
        $this->assertArrayNotHasKey('default', $options['url']);
        $this->assertArrayNotHasKey('default', $options['ttl']);
        $this->assertStringContainsString('telesign_verify: Verify API endpoint', $options['url']['description']);

        $this->assertStringStartsWith('telesign_verify only:', $options['methods']['description']);

        $this->assertSame(
            ['api_key', 'auth_token', 'api_secret', 'application_secret'],
            ChallengeDrivers::defaults()->sensitiveKeys()
        );
    }

    public function testRedactionIsMerged(): void
    {
        $redaction = ChallengeDrivers::defaults()->redaction();

        $this->assertSame(['security_factor', 'verify_code'], $redaction['fields']);
        $this->assertSame(['mac', 'otp'], $redaction['words']);
        $this->assertCount(3, $redaction['patterns']);
    }

    public function testASingleDriverRegistryKeepsItsOwnRequirements(): void
    {
        $drivers = new ChallengeDrivers([new \Kwidoo\SmsVerification\Challenge\Drivers\TelesignDriver()]);
        $options = array_column($drivers->options(), null, 'key');

        $this->assertTrue($options['customer_id']['required']);
        $this->assertSame('https://rest-api.telesign.com', $options['url']['default']);
    }

    public function testAHostCanAddItsOwnDriver(): void
    {
        $fake = new class implements ChallengeDriver
        {
            public function name(): string
            {
                return 'fake';
            }

            public function make(array $config, ChallengeRuntime $runtime): ChallengeVerifierInterface
            {
                return new class implements ChallengeVerifierInterface
                {
                    public function dispatch(string $recipient): Challenge
                    {
                        return new Challenge($recipient, 'fake-ref');
                    }

                    public function verify(Challenge $challenge, string $code): bool
                    {
                        return $code === '0000';
                    }
                };
            }

            public function options(): array
            {
                return [];
            }

            public function redaction(): array
            {
                return [];
            }
        };

        $drivers = ChallengeDrivers::defaults()->with($fake);

        $this->assertSame('fake-ref', $drivers->make('fake', [])->dispatch('+1')->reference);
        $this->assertSame('telesign', $drivers->defaultName());
    }
}
