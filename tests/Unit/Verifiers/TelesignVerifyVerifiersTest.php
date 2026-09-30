<?php

namespace Kwidoo\SmsVerification\Tests\Unit;

use DateTimeImmutable;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Kwidoo\SmsVerification\Challenge\Challenge;
use Kwidoo\SmsVerification\Exceptions\VerifierException;
use Kwidoo\SmsVerification\Tests\TestCase;
use Kwidoo\SmsVerification\VerifierFactory;
use Kwidoo\SmsVerification\Verifiers\TelesignSmsVerifyVerifier;
use Kwidoo\SmsVerification\Verifiers\TelesignVerifyVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use telesign\enterprise\sdk\verify\OmniVerifyClient;
use telesign\enterprise\sdk\verify\VerifyClient;

/**
 * Telesign-owned codes: Verify API (verify.telesign.com) and SMS Verify API
 * (/v1/verify/sms). Only the HTTP exchange is mocked; the real SDK signs and
 * builds every request.
 */
class TelesignVerifyVerifiersTest extends TestCase
{
    private const CUSTOMER = 'FFFFFFFF-EEEE-DDDD-1234-AB1234567890';

    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    private MockHandler $mock;

    private function stack(): HandlerStack
    {
        $this->mock = new MockHandler();
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));

        return $stack;
    }

    private function verify(array $options = []): TelesignVerifyVerifier
    {
        $client = new OmniVerifyClient(self::CUSTOMER, base64_encode('key'), 'https://verify.telesign.com', 10, null, $this->stack());

        return new TelesignVerifyVerifier($client, $options);
    }

    private function smsVerify(array $options = []): TelesignSmsVerifyVerifier
    {
        $client = new VerifyClient(self::CUSTOMER, base64_encode('key'), 'https://rest-ww.telesign.com', 10, null, $this->stack());

        return new TelesignSmsVerifyVerifier($client, $options);
    }

    private function telesignJson(int $http, array $body): Response
    {
        return new Response($http, ['Content-Type' => 'application/json'], json_encode($body));
    }

    private function body(int $index): array
    {
        return json_decode((string) $this->history[$index]['request']->getBody(), true) ?? [];
    }

    // ---- Verify API ------------------------------------------------------

    public function testVerifyApiCreatesAVerificationWithBasicAuth(): void
    {
        $verifier = $this->verify(['methods' => 'whatsapp, sms', 'message_template' => 'iorys_otp']);
        $this->mock->append($this->telesignJson(200, ['reference_id' => 'VREF', 'status' => ['code' => 3901, 'description' => 'Request in progress']]));

        $challenge = $verifier->dispatch('+371 2000 0000');

        $request = $this->history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://verify.telesign.com/verification', (string) $request->getUri());
        $this->assertSame('Basic '.base64_encode(self::CUSTOMER.':'.base64_encode('key')), $request->getHeaderLine('Authorization'));
        $this->assertStringStartsWith('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSame([
            'verification_policy' => [['method' => 'whatsapp'], ['method' => 'sms']],
            'message_template' => ['name' => 'iorys_otp'],
            'recipient' => ['phone_number' => '37120000000'],
        ], $this->body(0));

        $this->assertSame('VREF', $challenge->reference);
        $this->assertSame([], $challenge->state);
        $this->assertSame('+37120000000', $challenge->recipient);
        $this->assertNotNull($challenge->expiresAt);
    }

    public function testVerifyApiDefaultsToSms(): void
    {
        $verifier = $this->verify();
        $this->mock->append($this->telesignJson(200, ['reference_id' => 'VREF', 'status' => ['code' => 3901]]));

        $verifier->dispatch('+37120000000');

        $this->assertSame([['method' => 'sms']], $this->body(0)['verification_policy']);
        $this->assertArrayNotHasKey('message_template', $this->body(0));
    }

    public function testVerifyApiFinalizesTheCode(): void
    {
        $verifier = $this->verify();
        $this->mock->append($this->telesignJson(200, ['status' => ['code' => 3900, 'description' => 'Verified']]));

        $this->assertTrue($verifier->verify(new Challenge('+37120000000', 'VREF', [], new DateTimeImmutable('+5 minutes')), ' 5724433 '));

        $request = $this->history[0]['request'];
        $this->assertSame('PATCH', $request->getMethod());
        $this->assertSame('https://verify.telesign.com/verification/VREF/state', (string) $request->getUri());
        $this->assertSame(['action' => 'finalize', 'security_factor' => '5724433'], $this->body(0));
    }

    #[DataProvider('rejectedVerifyStatuses')]
    public function testVerifyApiRejectionsAreAVerdict(int $http, int $status): void
    {
        $verifier = $this->verify();
        $this->mock->append($this->telesignJson($http, ['status' => ['code' => $status]]));

        $this->assertFalse($verifier->verify(new Challenge('+37120000000', 'VREF'), '123456'));
    }

    public static function rejectedVerifyStatuses(): array
    {
        return [
            'wrong code' => [200, 3904],
            'attempts used up' => [200, 3909],
            'code expired' => [400, 3935],
            'verification expired' => [400, 3908],
            'already finalized' => [400, 3931],
        ];
    }

    public function testVerifyApiOutageIsAnError(): void
    {
        $verifier = $this->verify();
        $this->mock->append($this->telesignJson(503, ['status' => ['code' => 3500, 'description' => 'System Unavailable']]));

        $this->expectException(VerifierException::class);
        $this->expectExceptionMessage('3500');

        $verifier->verify(new Challenge('+37120000000', 'VREF'), '123456');
    }

    public function testVerifyApiDoesNotCallTelesignForUnusableInput(): void
    {
        $verifier = $this->verify();

        $this->assertFalse($verifier->verify(new Challenge('+37120000000', 'VREF'), 'abc'));
        $this->assertFalse($verifier->verify(new Challenge('+37120000000', 'VREF', [], new DateTimeImmutable('-1 second')), '123456'));
        $this->assertCount(0, $this->history);
    }

    public function testVerifyApiRefusalOnCreateIsAnError(): void
    {
        $verifier = $this->verify();
        $this->mock->append($this->telesignJson(400, ['status' => ['code' => 3101, 'description' => 'Phone number is invalid']]));

        $this->expectException(VerifierException::class);
        $this->expectExceptionMessage('3101');

        $verifier->dispatch('123');
    }

    public function testVerifyApiLegacyCreateAndValidate(): void
    {
        $verifier = $this->verify();
        $this->mock->append(
            $this->telesignJson(200, ['reference_id' => 'VREF', 'status' => ['code' => 3901]]),
            $this->telesignJson(200, ['status' => ['code' => 3900]]),
        );

        $verifier->create('+37120000000');

        $this->assertTrue($verifier->validate(['+37120000000', '123456']));
        $this->assertStringEndsWith('/verification/VREF/state', (string) $this->history[1]['request']->getUri());
    }

    // ---- SMS Verify API --------------------------------------------------

    public function testSmsVerifySendsATelesignGeneratedCode(): void
    {
        $verifier = $this->smsVerify(['template' => 'ACME code: :code', 'language' => 'en-US']);
        $this->mock->append($this->telesignJson(200, ['reference_id' => 'SREF', 'status' => ['code' => 290, 'description' => 'Message in progress']]));

        $challenge = $verifier->dispatch('+37120000000');

        $request = $this->history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://rest-ww.telesign.com/v1/verify/sms', (string) $request->getUri());
        $this->assertStringStartsWith('TSA '.self::CUSTOMER.':', $request->getHeaderLine('Authorization'));

        parse_str((string) $request->getBody(), $fields);
        $this->assertSame('37120000000', $fields['phone_number']);
        $this->assertSame('ACME code: $$CODE$$', $fields['template']);
        $this->assertSame('en-US', $fields['language']);
        $this->assertArrayNotHasKey('verify_code', $fields, 'Telesign must generate the code');

        $this->assertSame('SREF', $challenge->reference);
        $this->assertSame([], $challenge->state);
    }

    public function testSmsVerifyWithoutTemplateUsesTelesignsText(): void
    {
        $verifier = $this->smsVerify();
        $this->mock->append($this->telesignJson(200, ['reference_id' => 'SREF', 'status' => ['code' => 290]]));

        $verifier->dispatch('+37120000000');

        parse_str((string) $this->history[0]['request']->getBody(), $fields);
        $this->assertSame(['phone_number' => '37120000000'], $fields);
    }

    #[DataProvider('smsVerifyStates')]
    public function testSmsVerifyChecksTheCodeState(string $state, bool $expected): void
    {
        $verifier = $this->smsVerify();
        $this->mock->append($this->telesignJson(200, [
            'reference_id' => 'SREF',
            'status' => ['code' => 200, 'description' => 'Delivered to handset'],
            'verify' => ['code_state' => $state, 'code_entered' => '1234567'],
        ]));

        $this->assertSame($expected, $verifier->verify(new Challenge('+37120000000', 'SREF'), '1234567'));

        $request = $this->history[0]['request'];
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/v1/verify/SREF', $request->getUri()->getPath());
        $this->assertSame('verify_code=1234567', $request->getUri()->getQuery());
    }

    public static function smsVerifyStates(): array
    {
        return [
            'valid' => ['VALID', true],
            'invalid' => ['INVALID', false],
            'expired' => ['EXPIRED', false],
            'max attempts' => ['MAX_ATTEMPTS_EXCEEDED', false],
            'unknown' => ['UNKNOWN', false],
        ];
    }

    public function testSmsVerifyUnknownReferenceIsAnError(): void
    {
        $verifier = $this->smsVerify();
        $this->mock->append($this->telesignJson(404, ['status' => ['code' => 10000], 'errors' => [['code' => -30000, 'description' => 'Invalid reference_id']]]));

        $this->expectException(VerifierException::class);

        $verifier->verify(new Challenge('+37120000000', 'NOPE'), '123456');
    }

    public function testChallengeWithoutReferenceIsAnError(): void
    {
        $this->expectException(VerifierException::class);

        $this->smsVerify()->verify(new Challenge('+37120000000'), '123456');
    }

    // ---- Factory ---------------------------------------------------------

    public function testFactoryBuildsBothVerifiersWithTheirEndpoints(): void
    {
        config([
            'sms-verification.telesign.customer_id' => self::CUSTOMER,
            'sms-verification.telesign.api_key' => base64_encode('key'),
        ]);

        $factory = $this->app->make(VerifierFactory::class);

        $this->assertInstanceOf(TelesignVerifyVerifier::class, $factory->make('telesignVerify'));
        $this->assertInstanceOf(TelesignSmsVerifyVerifier::class, $factory->make('telesignSmsVerify'));

        $endpoint = static fn (object $client) => (fn () => $this->rest_endpoint)->call($client);
        $this->assertSame('https://verify.telesign.com', $endpoint($this->app->make(OmniVerifyClient::class)));
        $this->assertSame('https://rest-ww.telesign.com', $endpoint($this->app->make(VerifyClient::class)));
    }
}
