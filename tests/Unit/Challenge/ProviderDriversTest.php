<?php

namespace Kwidoo\SmsVerification\Tests\Unit;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Kwidoo\SmsVerification\Challenge\Challenge;
use Kwidoo\SmsVerification\Challenge\ChallengeDrivers;
use Kwidoo\SmsVerification\Challenge\ChallengeRuntime;
use Kwidoo\SmsVerification\Contracts\ChallengeVerifierInterface;
use Kwidoo\SmsVerification\Exceptions\ConfigurationException;
use Kwidoo\SmsVerification\Exceptions\VerifierException;
use Kwidoo\SmsVerification\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;

/**
 * The non-Telesign drivers, through the real SDKs, with only HTTP mocked by
 * the host handler every driver must send through.
 */
class ProviderDriversTest extends TestCase
{
    /** @var list<RequestInterface> */
    private array $sent = [];

    private MockHandler $mock;

    private function verifier(string $driver, array $config): ChallengeVerifierInterface
    {
        $this->mock = new MockHandler();

        return ChallengeDrivers::defaults()->make($driver, $config, new ChallengeRuntime(
            httpHandler: function (RequestInterface $request, array $options) {
                $this->sent[] = $request;

                return ($this->mock)($request, $options);
            },
            codeKey: str_repeat('k', 32),
            timeout: 5.0,
        ));
    }

    private function reply(int $status, array $body): void
    {
        $this->mock->append(new Response($status, ['Content-Type' => 'application/json'], json_encode($body)));
    }

    private function body(int $index): array
    {
        $raw = (string) $this->sent[$index]->getBody();
        $json = json_decode($raw, true);

        if (is_array($json)) {
            return $json;
        }

        parse_str($raw, $form);

        return $form;
    }

    // ---- Twilio ----------------------------------------------------------

    private function twilio(): ChallengeVerifierInterface
    {
        return $this->verifier('twilio', ['account_sid' => 'AC123', 'auth_token' => 'secret', 'verify_sid' => 'VA123', 'locale' => 'de']);
    }

    public function testTwilioStartsAVerificationAndChecksItBySid(): void
    {
        $verifier = $this->twilio();
        $this->reply(201, ['sid' => 'VE1', 'status' => 'pending']);
        $this->reply(200, ['sid' => 'VE1', 'status' => 'approved', 'valid' => true]);

        $challenge = $verifier->dispatch('+37120000000');

        $this->assertSame('VE1', $challenge->reference);
        $this->assertSame([], $challenge->state);
        $this->assertSame('https://verify.twilio.com/v2/Services/VA123/Verifications', (string) $this->sent[0]->getUri());
        $this->assertSame('Basic '.base64_encode('AC123:secret'), $this->sent[0]->getHeaderLine('Authorization'));
        $this->assertSame(['To' => '+37120000000', 'Channel' => 'sms', 'Locale' => 'de'], $this->body(0));

        $this->assertTrue($verifier->verify($challenge, '123456'));
        $this->assertSame('https://verify.twilio.com/v2/Services/VA123/VerificationCheck', (string) $this->sent[1]->getUri());
        $this->assertSame(['Code' => '123456', 'VerificationSid' => 'VE1'], $this->body(1));
    }

    public function testTwilioWrongExpiredAndExhaustedAreVerdicts(): void
    {
        $verifier = $this->twilio();
        $this->reply(200, ['sid' => 'VE1', 'status' => 'pending', 'valid' => false]);
        $this->reply(404, ['code' => 20404, 'message' => 'The requested resource was not found', 'status' => 404]);
        $this->reply(429, ['code' => 60202, 'message' => 'Max check attempts reached', 'status' => 429]);

        foreach ([1, 2, 3] as $ignored) {
            $this->assertFalse($verifier->verify(new Challenge('+37120000000', 'VE1'), '000000'));
        }
    }

    public function testTwilioOutageIsAnError(): void
    {
        $verifier = $this->twilio();
        $this->reply(503, ['code' => 20500, 'message' => 'Internal Server Error', 'status' => 503]);

        $this->expectException(VerifierException::class);

        $verifier->verify(new Challenge('+37120000000', 'VE1'), '123456');
    }

    // ---- Vonage ----------------------------------------------------------

    private function vonage(): ChallengeVerifierInterface
    {
        return $this->verifier('vonage', ['api_key' => 'key', 'api_secret' => 'secret', 'brand' => 'Iorys']);
    }

    public function testVonageStartsAVerificationAndChecksIt(): void
    {
        $verifier = $this->vonage();
        $this->reply(202, ['request_id' => 'REQ1']);
        $this->reply(200, ['request_id' => 'REQ1', 'status' => 'completed']);

        $challenge = $verifier->dispatch('+37120000000');

        $this->assertSame('REQ1', $challenge->reference);
        $this->assertSame('https://api.nexmo.com/v2/verify', (string) $this->sent[0]->getUri());
        $this->assertSame('Basic '.base64_encode('key:secret'), $this->sent[0]->getHeaderLine('Authorization'));
        $body = $this->body(0);
        $this->assertSame('Iorys', $body['brand']);
        $this->assertSame('sms', $body['workflow'][0]['channel']);
        $this->assertSame('37120000000', $body['workflow'][0]['to']);

        $this->assertTrue($verifier->verify($challenge, '1234'));
        $this->assertSame('https://api.nexmo.com/v2/verify/REQ1', (string) $this->sent[1]->getUri());
        $this->assertSame(['code' => '1234'], $this->body(1));
    }

    #[DataProvider('vonageRejections')]
    public function testVonageRejectionsAreVerdicts(int $status): void
    {
        $verifier = $this->vonage();
        $this->reply($status, ['title' => 'Invalid Code', 'detail' => 'The code you provided does not match']);

        $this->assertFalse($verifier->verify(new Challenge('+37120000000', 'REQ1'), '0000'));
    }

    public static function vonageRejections(): array
    {
        return ['invalid code' => [400], 'not found' => [404], 'too many attempts' => [410]];
    }

    public function testVonageRateLimitIsAnError(): void
    {
        $verifier = $this->vonage();
        $this->reply(429, ['title' => 'Rate Limit Hit']);

        $this->expectException(VerifierException::class);

        $verifier->verify(new Challenge('+37120000000', 'REQ1'), '0000');
    }

    // ---- Telnyx ----------------------------------------------------------

    private function telnyx(): ChallengeVerifierInterface
    {
        return $this->verifier('telnyx', ['api_key' => 'KEY', 'verify_profile_id' => 'PROFILE']);
    }

    public function testTelnyxSendsAnSmsVerificationAndChecksItById(): void
    {
        $verifier = $this->telnyx();
        $this->reply(200, ['data' => ['id' => 'V1', 'status' => 'pending']]);
        $this->reply(200, ['data' => ['phone_number' => '+37120000000', 'response_code' => 'accepted']]);
        $this->reply(200, ['data' => ['phone_number' => '+37120000000', 'response_code' => 'rejected']]);

        $challenge = $verifier->dispatch('+37120000000');

        $this->assertSame('V1', $challenge->reference);
        $this->assertSame('https://api.telnyx.com/v2/verifications/sms', (string) $this->sent[0]->getUri());
        $this->assertSame('Bearer KEY', $this->sent[0]->getHeaderLine('Authorization'));
        $this->assertSame(['phone_number' => '+37120000000', 'verify_profile_id' => 'PROFILE', 'timeout_secs' => 300], $this->body(0));

        $this->assertTrue($verifier->verify($challenge, '12345'));
        $this->assertSame('https://api.telnyx.com/v2/verifications/V1/actions/verify', (string) $this->sent[1]->getUri());
        $this->assertSame(['code' => '12345'], $this->body(1));

        $this->assertFalse($verifier->verify($challenge, '54321'));
    }

    public function testTelnyxLegacyValidateNowRejectsAWrongCode(): void
    {
        $verifier = $this->telnyx();
        $this->reply(200, ['data' => ['id' => 'V1']]);
        $this->reply(200, ['data' => ['response_code' => 'rejected']]);

        $verifier->create('+37120000000');

        $this->expectException(VerifierException::class);

        $verifier->validate(['+37120000000', '00000']);
    }

    // ---- Plivo -----------------------------------------------------------

    private function plivo(): ChallengeVerifierInterface
    {
        return $this->verifier('plivo', ['auth_id' => 'MAXXX', 'auth_token' => 'secret', 'app_uuid' => 'APP1', 'locale' => 'en']);
    }

    public function testPlivoCreatesASessionAndValidatesIt(): void
    {
        $verifier = $this->plivo();
        $this->reply(202, ['api_id' => 'a1', 'message' => 'Session initiated', 'session_uuid' => 'S1']);
        $this->reply(200, ['api_id' => 'a2', 'message' => 'session validated successfully.']);

        $challenge = $verifier->dispatch('+37120000000');

        $this->assertSame('S1', $challenge->reference);
        $this->assertStringEndsWith('/Account/MAXXX/Verify/Session/', (string) $this->sent[0]->getUri());
        $this->assertSame('Basic '.base64_encode('MAXXX:secret'), $this->sent[0]->getHeaderLine('Authorization'));
        $this->assertSame(['recipient' => '+37120000000', 'app_uuid' => 'APP1', 'channel' => 'sms', 'locale' => 'en'], $this->body(0));

        $this->assertTrue($verifier->verify($challenge, '123456'));
        $this->assertStringEndsWith('/Account/MAXXX/Verify/Session/S1/', (string) $this->sent[1]->getUri());
        $this->assertSame(['otp' => '123456'], $this->body(1));
    }

    public function testPlivoWrongOtpIsAVerdictAndOutageAnError(): void
    {
        $verifier = $this->plivo();
        $this->reply(400, ['api_id' => 'a3', 'error' => 'Invalid OTP.']);
        $this->reply(500, ['api_id' => 'a4', 'error' => 'Internal error']);

        $this->assertFalse($verifier->verify(new Challenge('+37120000000', 'S1'), '000000'));

        $this->expectException(VerifierException::class);
        $verifier->verify(new Challenge('+37120000000', 'S1'), '000000');
    }

    // ---- Sinch -----------------------------------------------------------

    private function sinch(): ChallengeVerifierInterface
    {
        return $this->verifier('sinch', ['application_key' => 'APPKEY', 'application_secret' => 'secret']);
    }

    public function testSinchStartsAVerificationAndReportsTheCodeById(): void
    {
        $verifier = $this->sinch();
        $this->reply(200, ['id' => '1018', '_links' => []]);
        $this->reply(200, ['id' => '1018', 'method' => 'sms', 'status' => 'SUCCESSFUL']);
        $this->reply(200, ['id' => '1018', 'method' => 'sms', 'status' => 'FAIL']);

        $challenge = $verifier->dispatch('+37120000000');

        $this->assertSame('1018', $challenge->reference);
        $this->assertSame('https://verification.api.sinch.com/verification/v1/verifications', (string) $this->sent[0]->getUri());
        $this->assertSame('Basic '.base64_encode('APPKEY:secret'), $this->sent[0]->getHeaderLine('Authorization'));
        $this->assertSame(['identity' => ['type' => 'number', 'endpoint' => '+37120000000'], 'method' => 'sms'], $this->body(0));

        $this->assertTrue($verifier->verify($challenge, '1234'));
        $this->assertSame('PUT', $this->sent[1]->getMethod());
        $this->assertSame('https://verification.api.sinch.com/verification/v1/verifications/id/1018', (string) $this->sent[1]->getUri());
        $this->assertSame(['method' => 'sms', 'sms' => ['code' => '1234']], $this->body(1));

        $this->assertFalse($verifier->verify($challenge, '9999'));
    }

    // ---- seven.io --------------------------------------------------------

    private function seven(array $config = []): ChallengeVerifierInterface
    {
        return $this->verifier('seven', ['api_key' => 'SEVENKEY', 'from' => 'Iorys', ...$config]);
    }

    private function sevenAccepted(): void
    {
        $this->reply(200, [
            'success' => '100', 'total_price' => 0.075, 'balance' => 1.0, 'debug' => 'false', 'sms_type' => 'direct',
            'messages' => [[
                'id' => '77229135982', 'sender' => 'Iorys', 'recipient' => '37120000000', 'text' => '...', 'encoding' => 'gsm',
                'label' => null, 'parts' => 1, 'udh' => null, 'is_binary' => false, 'price' => 0.075, 'success' => true,
                'error' => null, 'error_text' => null,
            ]],
        ]);
    }

    private function sevenCode(): string
    {
        preg_match('/(\d{4,10})$/', $this->body(0)['text'], $m);

        return $m[1];
    }

    public function testSevenSendsAGeneratedCodeAndSealsIt(): void
    {
        $verifier = $this->seven(['message' => 'ACME :code', 'code_length' => 5]);
        $this->sevenAccepted();

        $challenge = $verifier->dispatch('+37120000000');

        $this->assertSame('https://gateway.seven.io/api/sms', (string) $this->sent[0]->getUri());
        $this->assertSame('SEVENKEY', $this->sent[0]->getHeaderLine('X-Api-Key'));
        $body = $this->body(0);
        $this->assertSame('+37120000000', $body['to']);
        $this->assertSame('Iorys', $body['from']);
        $this->assertMatchesRegularExpression('/^ACME \d{5}$/', $body['text']);

        $code = $this->sevenCode();
        $this->assertSame('77229135982', $challenge->reference);
        $this->assertStringNotContainsString($code, json_encode($challenge));

        $restored = Challenge::fromArray(json_decode(json_encode($challenge), true));
        $this->assertTrue($verifier->verify($restored, $code));
        $this->assertFalse($verifier->verify($restored, str_pad((string) (((int) $code + 1) % 100000), 5, '0', STR_PAD_LEFT)));
        $this->assertCount(1, $this->sent, 'verify does not call seven.io');
    }

    public function testSevenRefusalIsAnError(): void
    {
        $verifier = $this->seven();
        $this->reply(200, ['success' => '201', 'total_price' => 0, 'balance' => 0, 'debug' => 'false', 'sms_type' => 'direct', 'messages' => []]);

        $this->expectException(VerifierException::class);

        $verifier->dispatch('+37120000000');
    }

    public function testSevenLegacyValidateComparesCodesAsStrings(): void
    {
        $verifier = $this->seven(['code_length' => 4]);
        $this->sevenAccepted();

        $verifier->create('+37120000000');

        $this->assertTrue($verifier->validate(['+37120000000', $this->sevenCode()]));
    }

    // ---- Shared ----------------------------------------------------------

    public static function requiredOptions(): array
    {
        return [
            'twilio' => ['twilio', ['account_sid' => 'AC', 'auth_token' => 't'], 'verify_sid'],
            'vonage' => ['vonage', ['api_key' => 'k', 'api_secret' => 's'], 'brand'],
            'telnyx' => ['telnyx', ['api_key' => 'k'], 'verify_profile_id'],
            'plivo' => ['plivo', ['auth_id' => 'a'], 'auth_token'],
            'sinch' => ['sinch', ['application_key' => 'k'], 'application_secret'],
            'seven' => ['seven', [], 'api_key'],
        ];
    }

    #[DataProvider('requiredOptions')]
    public function testMissingOptionsAreConfigurationErrors(string $driver, array $config, string $missing): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage($missing);

        $this->verifier($driver, $config);
    }

    public function testEveryProviderIsRegistered(): void
    {
        $this->assertSame(
            ['telesign', 'telesign_verify', 'telesign_sms_verify', 'twilio', 'vonage', 'telnyx', 'plivo', 'sinch', 'seven'],
            ChallengeDrivers::defaults()->names()
        );
    }

    public function testCodesInFormEncodedTrafficMatchTheRedactionPatterns(): void
    {
        $patterns = ChallengeDrivers::defaults()->redaction()['patterns'];

        $redact = static fn (string $value) => array_reduce($patterns, static fn ($v, $p) => preg_replace($p, '***', $v), $value);

        $this->assertStringNotContainsString('123456', $redact('Code=123456&VerificationSid=VE1'));
        $this->assertStringNotContainsString('123456', $redact('to=%2B371&text=Your+code+is+123456'));
        $this->assertSame('+37120000000', $redact('+37120000000'));
        $this->assertSame('VE1', $redact('VE1'));
        // Digit-only values are left alone (no rule may mask every status code).
        $this->assertSame('123456', $redact('123456'));
    }
}
