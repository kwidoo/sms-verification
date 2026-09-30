<?php

namespace Kwidoo\SmsVerification\Verifiers;

use DateTimeImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Kwidoo\SmsVerification\Challenge\Challenge;
use Kwidoo\SmsVerification\Challenge\OtpGenerator;
use Kwidoo\SmsVerification\Challenge\OtpHasher;
use Kwidoo\SmsVerification\Contracts\ChallengeVerifierInterface;
use Kwidoo\SmsVerification\Contracts\VerifierInterface;
use Kwidoo\SmsVerification\Exceptions\VerifierException;
use Kwidoo\SmsVerification\Verifiers\Concerns\TalksToTelesign;
use telesign\sdk\messaging\MessagingClient;

/**
 * SMS verification over the Telesign Messaging API (POST /v1/messaging).
 *
 * The Messaging API only delivers text, so this verifier generates the code
 * itself. Two ways of keeping it between send and check:
 *
 * - {@see ChallengeVerifierInterface}: dispatch() seals a keyed MAC of the code
 *   into the returned {@see Challenge}; verify() checks against it. Nothing
 *   is stored by the verifier. Requires an {@see OtpHasher}.
 * - {@see VerifierInterface} (1.x API): create() keeps the code in the cache,
 *   keyed by phone number, for validate().
 */
class TelesignVerifier extends Verifier implements VerifierInterface, ChallengeVerifierInterface
{
    use TalksToTelesign;

    public const DEFAULT_MESSAGE = 'Your verification code is :code';

    public const DEFAULT_MESSAGE_TYPE = 'OTP';

    public const DEFAULT_CODE_LENGTH = 6;

    public const DEFAULT_TTL = 300;

    private const CACHE_PREFIX = 'telesign';

    /**
     * @param  array{message?: string, message_type?: string, code_length?: int, ttl?: int}  $options
     */
    public function __construct(
        protected MessagingClient $client,
        protected ?OtpHasher $hasher = null,
        protected OtpGenerator $generator = new OtpGenerator(),
        protected array $options = [],
        protected ?CacheRepository $cache = null,
    ) {}

    public function dispatch(string $recipient): Challenge
    {
        if ($this->hasher === null) {
            throw new VerifierException('Telesign challenge verification requires a code key.');
        }

        $number = $this->sanitizePhoneNumber($recipient);
        $code = $this->generator->generate($this->codeLength());
        $reference = $this->send($number, $code);
        $expiresAt = new DateTimeImmutable(sprintf('+%d seconds', $this->ttl()));

        return new Challenge(
            recipient: $number,
            reference: $reference,
            state: $this->hasher->seal($code, $number, $reference, $expiresAt),
            expiresAt: $expiresAt,
        );
    }

    public function verify(Challenge $challenge, string $code): bool
    {
        if ($this->hasher === null) {
            throw new VerifierException('Telesign challenge verification requires a code key.');
        }

        $code = trim($code);

        if ($code === '' || !ctype_digit($code) || $challenge->isExpired()) {
            return false;
        }

        return $this->hasher->matches(
            $challenge->state,
            $code,
            $this->sanitizePhoneNumber($challenge->recipient),
            $challenge->reference,
            $challenge->expiresAt,
        );
    }

    public function create(string $phoneNumber): void
    {
        $number = $this->sanitizePhoneNumber($phoneNumber);
        $code = $this->generator->generate($this->codeLength());

        $this->send($number, $code);

        $this->cache()->put(self::CACHE_PREFIX.$number, $code, now()->addSeconds($this->ttl()));
    }

    public function validate(array $credentials): bool
    {
        if (count($credentials) < 2) {
            throw new VerifierException('Credentials array must include [phoneNumber, code].');
        }

        [$phoneNumber, $verificationCode] = $credentials;
        $number = $this->sanitizePhoneNumber($phoneNumber);

        $code = $this->cache()->pull(self::CACHE_PREFIX.$number);

        // Codes are compared as strings: a code cached by 1.x as an int and
        // a code typed by a user must still compare equal.
        if ($code === null || !hash_equals((string) $code, trim((string) $verificationCode))) {
            throw new VerifierException('Invalid verification code');
        }

        return true;
    }

    /**
     * Sends the message and returns Telesign's reference_id.
     *
     * @throws VerifierException
     */
    protected function send(string $number, string $code): string
    {
        $message = str_replace(':code', $code, (string) ($this->options['message'] ?? self::DEFAULT_MESSAGE));

        $response = $this->callTelesign(fn () => $this->client->message(
            $this->telesignNumber($number),
            $message,
            (string) ($this->options['message_type'] ?? self::DEFAULT_MESSAGE_TYPE)
        ));

        return $this->referenceFrom($response, 'message');
    }

    private function codeLength(): int
    {
        return (int) ($this->options['code_length'] ?? self::DEFAULT_CODE_LENGTH);
    }

    private function ttl(): int
    {
        return max(1, (int) ($this->options['ttl'] ?? self::DEFAULT_TTL));
    }

    private function cache(): CacheRepository
    {
        return $this->cache ?? cache()->store();
    }
}
