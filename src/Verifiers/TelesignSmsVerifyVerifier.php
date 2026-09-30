<?php

namespace Kwidoo\SmsVerification\Verifiers;

use DateTimeImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Kwidoo\SmsVerification\Challenge\Challenge;
use Kwidoo\SmsVerification\Contracts\ChallengeVerifierInterface;
use Kwidoo\SmsVerification\Contracts\VerifierInterface;
use Kwidoo\SmsVerification\Exceptions\VerifierException;
use Kwidoo\SmsVerification\Verifiers\Concerns\TalksToTelesign;
use telesign\enterprise\sdk\verify\VerifyClient;

/**
 * Telesign SMS Verify API (/v1/verify/sms, full-service accounts).
 *
 * Telesign generates, delivers and checks the code:
 *   POST /v1/verify/sms                         -> reference_id
 *   GET  /v1/verify/{reference_id}?verify_code  -> verify.code_state VALID | INVALID | EXPIRED | MAX_ATTEMPTS_EXCEEDED
 *
 * The challenge carries only Telesign's reference_id.
 */
class TelesignSmsVerifyVerifier extends Verifier implements VerifierInterface, ChallengeVerifierInterface
{
    use TalksToTelesign;

    public const DEFAULT_TTL = 300;

    private const CACHE_PREFIX = 'telesign_sms_verify';

    /**
     * @param  array{template?: ?string, language?: ?string, ttl?: int}  $options  `template` may use `:code` or Telesign's `$$CODE$$`.
     */
    public function __construct(
        protected VerifyClient $client,
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
            throw new VerifierException('Telesign SMS Verify challenge has no reference_id.');
        }

        return $this->check($challenge->reference, $code);
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

        if ($code === null || !$this->check($reference, $code)) {
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
        $params = [];

        if (!empty($this->options['template'])) {
            $params['template'] = str_replace(':code', '$$CODE$$', (string) $this->options['template']);
        }

        if (!empty($this->options['language'])) {
            $params['language'] = (string) $this->options['language'];
        }

        $response = $this->callTelesign(fn () => $this->client->sms($this->telesignNumber($number), $params));

        return $this->referenceFrom($response, 'verification SMS');
    }

    /**
     * @throws VerifierException
     */
    protected function check(string $reference, string $code): bool
    {
        $response = $this->callTelesign(fn () => $this->client->status($reference, ['verify_code' => $code]));

        if (!$response->ok) {
            throw new VerifierException('Telesign could not check the code: '.$this->describeTelesignFailure($response));
        }

        $state = is_array($response->json) ? ($response->json['verify']['code_state'] ?? null) : null;

        return $state === 'VALID';
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
