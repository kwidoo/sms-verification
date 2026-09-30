<?php

namespace Kwidoo\SmsVerification\Tests\Unit;

use DateTimeImmutable;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Kwidoo\SmsVerification\Challenge\Challenge;
use Kwidoo\SmsVerification\Challenge\OtpGenerator;
use Kwidoo\SmsVerification\Challenge\OtpHasher;
use Kwidoo\SmsVerification\Exceptions\VerifierException;
use Kwidoo\SmsVerification\Tests\TestCase;
use Kwidoo\SmsVerification\VerifierFactory;
use Kwidoo\SmsVerification\Verifiers\TelesignVerifier;
use telesign\sdk\messaging\MessagingClient;

class TelesignVerifierTest extends TestCase
{
    private const ENDPOINT = 'https://rest-api.telesign.com';

    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    private function client(Response ...$responses): MessagingClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new MessagingClient(
            'FFFFFFFF-EEEE-DDDD-1234-AB1234567890',
            base64_encode('telesign-test-api-key'),
            self::ENDPOINT,
            'php_telesign',
            null,
            null,
            10,
            null,
            $stack,
        );
    }

    private function accepted(string $reference = '0123456789ABCDEF0123456789ABCDEF'): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'reference_id' => $reference,
            'status' => ['code' => 290, 'description' => 'Message in progress'],
        ]));
    }

    private function verifier(MessagingClient $client, array $options = []): TelesignVerifier
    {
        return new TelesignVerifier($client, new OtpHasher(str_repeat('k', 32)), new OtpGenerator(), $options);
    }

    /**
     * @return array<string, string>
     */
    private function sentFields(int $index = 0): array
    {
        parse_str((string) $this->history[$index]['request']->getBody(), $fields);

        return $fields;
    }

    private function sentCode(int $index = 0): string
    {
        preg_match('/(\d{4,10})/', $this->sentFields($index)['message'], $m);

        return $m[1];
    }

    public function testDispatchSendsOtpMessageToProductionEndpoint(): void
    {
        $challenge = $this->verifier($this->client($this->accepted('REF1')))->dispatch('+371 2000-0000');

        $request = $this->history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('rest-api.telesign.com', $request->getUri()->getHost());
        $this->assertSame('/v1/messaging', $request->getUri()->getPath());
        $this->assertStringStartsWith('TSA FFFFFFFF-EEEE-DDDD-1234-AB1234567890:', $request->getHeaderLine('Authorization'));

        $fields = $this->sentFields();
        // Telesign wants digits only, no '+'.
        $this->assertSame('37120000000', $fields['phone_number']);
        $this->assertSame('OTP', $fields['message_type']);
        $this->assertMatchesRegularExpression('/^Your verification code is \d{6}$/', $fields['message']);

        $this->assertSame('+37120000000', $challenge->recipient);
        $this->assertSame('REF1', $challenge->reference);
        $this->assertNotNull($challenge->expiresAt);
    }

    public function testChallengeStateNeverContainsTheCode(): void
    {
        $challenge = $this->verifier($this->client($this->accepted()))->dispatch('+37120000000');

        $this->assertStringNotContainsString($this->sentCode(), json_encode($challenge->toArray()));
    }

    public function testVerifyAcceptsTheSentCodeAfterARoundTrip(): void
    {
        $verifier = $this->verifier($this->client($this->accepted()));
        $challenge = $verifier->dispatch('+37120000000');

        // As a caller would persist and restore it.
        $restored = Challenge::fromArray(json_decode(json_encode($challenge), true));

        $this->assertTrue($verifier->verify($restored, $this->sentCode()));
        $this->assertTrue($verifier->verify($restored, ' '.$this->sentCode().' '));
    }

    public function testVerifyRejectsWrongCode(): void
    {
        $verifier = $this->verifier($this->client($this->accepted()));
        $challenge = $verifier->dispatch('+37120000000');
        $wrong = str_pad((string) (((int) $this->sentCode() + 1) % 1000000), 6, '0', STR_PAD_LEFT);

        $this->assertFalse($verifier->verify($challenge, $wrong));
        $this->assertFalse($verifier->verify($challenge, ''));
        $this->assertFalse($verifier->verify($challenge, 'abcdef'));
    }

    public function testVerifyAcceptsTheExpiryRenderedInAnotherTimezone(): void
    {
        $verifier = $this->verifier($this->client($this->accepted()));
        $challenge = $verifier->dispatch('+37120000000');

        $data = $challenge->toArray();
        $data['expires_at'] = $challenge->expiresAt->setTimezone(new \DateTimeZone('Asia/Tokyo'))->format(DATE_ATOM);

        $this->assertTrue($verifier->verify(Challenge::fromArray($data), $this->sentCode()));
    }

    public function testVerifyRejectsExpiredChallenge(): void
    {
        $verifier = $this->verifier($this->client($this->accepted()), ['ttl' => 300]);
        $challenge = $verifier->dispatch('+37120000000');

        $expired = new Challenge(
            $challenge->recipient,
            $challenge->reference,
            $challenge->state,
            new DateTimeImmutable('-1 second'),
        );

        $this->assertFalse($verifier->verify($expired, $this->sentCode()));
    }

    public function testVerifyRejectsStateMovedToAnotherReferenceOrRecipient(): void
    {
        $verifier = $this->verifier($this->client($this->accepted('REF1')));
        $challenge = $verifier->dispatch('+37120000000');
        $code = $this->sentCode();

        $otherReference = new Challenge($challenge->recipient, 'REF2', $challenge->state, $challenge->expiresAt);
        $otherRecipient = new Challenge('+37129999999', $challenge->reference, $challenge->state, $challenge->expiresAt);
        $otherExpiry = new Challenge($challenge->recipient, $challenge->reference, $challenge->state, $challenge->expiresAt->modify('+1 hour'));

        $this->assertFalse($verifier->verify($otherReference, $code));
        $this->assertFalse($verifier->verify($otherRecipient, $code));
        $this->assertFalse($verifier->verify($otherExpiry, $code));
    }

    public function testVerifyRejectsStateSealedWithAnotherKey(): void
    {
        $challenge = $this->verifier($this->client($this->accepted()))->dispatch('+37120000000');
        $other = new TelesignVerifier($this->client(), new OtpHasher(str_repeat('x', 32)));

        $this->assertFalse($other->verify($challenge, $this->sentCode()));
    }

    public function testMessageTemplateAndCodeLengthAreConfigurable(): void
    {
        $this->verifier($this->client($this->accepted()), ['message' => 'ACME code: :code', 'code_length' => 4])
            ->dispatch('+37120000000');

        $this->assertMatchesRegularExpression('/^ACME code: \d{4}$/', $this->sentFields()['message']);
    }

    public function testRefusedMessageThrowsWithTelesignDetailButNoCode(): void
    {
        $refused = new Response(400, ['Content-Type' => 'application/json'], json_encode([
            'status' => ['code' => 11000, 'description' => 'Invalid value for parameter phone_number.'],
            'errors' => [['code' => -10001, 'description' => 'Invalid Request: PhoneNumber Parameter: 123']],
        ]));

        try {
            $this->verifier($this->client($refused))->dispatch('123');
            $this->fail('Expected VerifierException');
        } catch (VerifierException $e) {
            $this->assertStringContainsString('HTTP 400', $e->getMessage());
            $this->assertStringContainsString('11000', $e->getMessage());
            $this->assertStringNotContainsString($this->sentCode(), $e->getMessage());
        }
    }

    public function testResponseWithoutReferenceThrows(): void
    {
        $this->expectException(VerifierException::class);

        $this->verifier($this->client(new Response(200, [], '{}')))->dispatch('+37120000000');
    }

    public function testDispatchWithoutKeyThrows(): void
    {
        $this->expectException(VerifierException::class);

        (new TelesignVerifier($this->client($this->accepted())))->dispatch('+37120000000');
    }

    public function testLegacyCreateAndValidateCompareCodesAsStrings(): void
    {
        $verifier = new TelesignVerifier($this->client($this->accepted()));
        $verifier->create('+37120000000');

        // 1.x cached an int and compared it !== to the submitted string, so
        // validate() could never succeed.
        $this->assertTrue($verifier->validate(['+37120000000', $this->sentCode()]));
    }

    public function testLegacyValidateIsSingleUse(): void
    {
        $verifier = new TelesignVerifier($this->client($this->accepted()));
        $verifier->create('+37120000000');
        $code = $this->sentCode();

        $verifier->validate(['+37120000000', $code]);

        $this->expectException(VerifierException::class);
        $verifier->validate(['+37120000000', $code]);
    }

    public function testLegacyValidateRejectsWrongCode(): void
    {
        $verifier = new TelesignVerifier($this->client($this->accepted()));
        $verifier->create('+37120000000');

        $this->expectException(VerifierException::class);
        $verifier->validate(['+37120000000', 'nope']);
    }

    public function testFactoryBuildsTelesignVerifierWithExplicitEndpoint(): void
    {
        config([
            'sms-verification.telesign.customer_id' => 'FFFFFFFF-EEEE-DDDD-1234-AB1234567890',
            'sms-verification.telesign.api_key' => base64_encode('telesign-test-api-key'),
            'sms-verification.challenge.key' => str_repeat('k', 32),
        ]);

        $client = $this->app->make(MessagingClient::class);
        $endpoint = (fn () => $this->rest_endpoint)->call($client);

        $this->assertSame(self::ENDPOINT, $endpoint);
        $this->assertInstanceOf(TelesignVerifier::class, $this->app->make(VerifierFactory::class)->make('telesign'));
    }
}
