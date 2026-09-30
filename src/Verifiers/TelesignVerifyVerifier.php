<?php

namespace Kwidoo\SmsVerification\Verifiers;

use DateTimeImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Kwidoo\SmsVerification\Challenge\Challenge;
use Kwidoo\SmsVerification\Contracts\ChallengeVerifierInterface;
use Kwidoo\SmsVerification\Contracts\VerifierInterface;
use Kwidoo\SmsVerification\Exceptions\VerifierException;
use Kwidoo\SmsVerification\Verifiers\Concerns\TalksToTelesign;
use telesign\enterprise\sdk\verify\OmniVerifyClient;

/**
 * Telesign Verify API (verify.telesign.com, full-service accounts).
 *
 * Telesign generates, delivers and checks the code:
 *   POST  /verification                       -> reference_id (status 3901)
 *   PATCH /verification/{reference_id}/state  {action: finalize, security_factor}
 *                                             -> 3900 verified, 3904/3909/... rejected
 *
 * The challenge carries only Telesign's reference_id; nothing about the code
 * is kept on this side.
 */
class TelesignVerifyVerifier extends Verifier implements VerifierInterface, ChallengeVerifierInterface
{
    use TalksToTelesign;

    public const DEFAULT_TTL = 300;

    public const STATUS_VERIFIED = 3900;

    /**
     * Finalize outcomes that are a verdict on the code, not a failure:
     * 3904 verification failed (wrong code), 3908 verification time expired,
     * 3909 invalid code entered (attempts used up), 3931 invalid workflow
     * (already finalized), 3933 method expired, 3935 code validity expired.
     */
    public const REJECTED_STATUSES = [3904, 3908, 3909, 3931, 3933, 3935];

    private const CACHE_PREFIX = 'telesign_verify';

    /**
     * @param  array{methods?: list<string>|string, ttl?: int, message_template?: ?string}  $options
     */
    public function __construct(
        protected OmniVerifyClient $client,
        protected array $options = [],
        protected ?CacheRepository $cache = null,
    ) {}

    public function dispatch(string $recipient): Challenge
    {
        $number = $this->sanitizePhoneNumber($recipient);

        return new Challenge(
            recipient: $number,
            reference: $this->start($number),
            state: [],
            expiresAt: new DateTimeImmutable(sprintf('+%d seconds', $this->ttl())),
        );
    }

    public function verify(Challenge $challenge, string $code): bool
    {
        $code = $this->normalizeCode($code);

        if ($code === null || $challenge->isExpired()) {
            return false;
        }

        if ($challenge->reference === null || $challenge->reference === '') {
            throw new VerifierException('Telesign Verify challenge has no reference_id.');
        }

        return $this->finalize($challenge->reference, $code);
    }

    public function create(string $phoneNumber): void
    {
        $number = $this->sanitizePhoneNumber($phoneNumber);

        $this->cache()->put(self::CACHE_PREFIX.$number, $this->start($number), now()->addSeconds($this->ttl()));
    }

    public function validate(array $credentials): bool
    {
        if (count($credentials) < 2) {
            throw new VerifierException('Credentials array must include [phoneNumber, code].');
        }

        [$phoneNumber, $code] = $credentials;
        $number = $this->sanitizePhoneNumber($phoneNumber);
        $reference = $this->cache()->get(self::CACHE_PREFIX.$number);

        if (!$reference) {
            throw new VerifierException('No Telesign verification found for this number.');
        }

        $code = $this->normalizeCode((string) $code);

        if ($code === null || !$this->finalize($reference, $code)) {
            throw new VerifierException('Invalid verification code');
        }

        $this->cache()->forget(self::CACHE_PREFIX.$number);

        return true;
    }

    /**
     * @throws VerifierException
     */
    protected function start(string $number): string
    {
        $params = ['verification_policy' => $this->policy()];

        if (!empty($this->options['message_template'])) {
            $params['message_template'] = ['name' => (string) $this->options['message_template']];
        }

        $response = $this->callTelesign(fn () => $this->client->create($this->telesignNumber($number), $params));

        return $this->referenceFrom($response, 'verification');
    }

    /**
     * @throws VerifierException
     */
    protected function finalize(string $reference, string $code): bool
    {
        $response = $this->callTelesign(fn () => $this->client->update(
            $reference,
            ['action' => 'finalize', 'security_factor' => $code]
        ));

        $status = $this->telesignStatusCode($response);

        if ($response->ok && $status === self::STATUS_VERIFIED) {
            return true;
        }

        if ($status !== null && in_array($status, self::REJECTED_STATUSES, true)) {
            return false;
        }

        throw new VerifierException('Telesign could not finalize the verification: '.$this->describeTelesignFailure($response));
    }

    /**
     * @return list<array{method: string}>
     */
    private function policy(): array
    {
        $methods = $this->options['methods'] ?? ['sms'];

        if (is_string($methods)) {
            $methods = explode(',', $methods);
        }

        $methods = array_values(array_filter(array_map(
            static fn ($method) => strtolower(trim((string) $method)),
            (array) $methods
        )));

        return array_map(static fn (string $method) => ['method' => $method], $methods ?: ['sms']);
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
