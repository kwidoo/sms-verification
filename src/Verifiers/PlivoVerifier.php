<?php

namespace Kwidoo\SmsVerification\Verifiers;

use Kwidoo\SmsVerification\Challenge\Challenge;
use Kwidoo\SmsVerification\Contracts\ChallengeVerifierInterface;
use Kwidoo\SmsVerification\Contracts\VerifierInterface;
use Kwidoo\SmsVerification\Exceptions\VerifierException;
use Kwidoo\SmsVerification\Verifiers\Concerns\ProviderOwnedChallenges;
use Plivo\Exceptions\PlivoResponseException;
use Plivo\Resources\Verify\VerifySessionInterface;
use Plivo\RestClient as Client;

/**
 * Plivo Verify sessions: Plivo generates, delivers and checks the code.
 *
 * Challenge reference = the session_uuid.
 */
class PlivoVerifier extends Verifier implements VerifierInterface, ChallengeVerifierInterface
{
    use ProviderOwnedChallenges;

    /** Plivo sessions expire after 10 minutes by default. */
    public const DEFAULT_TTL = 600;

    /** Session validation outcomes that are a verdict: wrong OTP, expired or unknown session, too many attempts. */
    private const REJECTED_HTTP = [400, 404, 410, 422];

    /**
     * @param  array{app_uuid?: ?string, channel?: ?string, locale?: ?string, brand_name?: ?string, code_length?: ?int, ttl?: int}  $options
     *                                                   Without options the 1.x config (`plivo.optional_args`) is used.
     * @param  VerifySessionInterface|null  $sessions  Sessions over a specific Plivo client; defaults to `$client->verifySessions`.
     */
    public function __construct(
        protected Client $client,
        protected array $options = [],
        protected ?VerifySessionInterface $sessions = null,
    ) {}

    public function create(string $phoneNumber): void
    {
        $number = $this->sanitizePhoneNumber($phoneNumber);

        cache()->put("plivo$number", $this->startVerification($number), now()->addMinutes(5));
    }

    public function validate(array $credentials): bool
    {
        if (count($credentials) < 2) {
            throw new VerifierException('Credentials array must include [phoneNumber, code].');
        }

        [$phoneNumber, $verificationCode] = $credentials;
        $number = $this->sanitizePhoneNumber($phoneNumber);
        $sessionId = cache()->pull("plivo$number");

        if (!$sessionId) {
            throw new VerifierException('No Plivo verification session found for this number.');
        }

        if (!$this->checkCode(new Challenge($number, $sessionId), trim((string) $verificationCode))) {
            throw new VerifierException('Invalid verification code');
        }

        return true;
    }

    protected function startVerification(string $number): string
    {
        try {
            $response = $this->sessions()->create($number, $this->sessionArgs());
        } catch (\Throwable $e) {
            throw new VerifierException('Plivo refused the verification session: '.$e->getMessage(), 0, $e);
        }

        // 1.x read $response['session_uuid'] from an object without ArrayAccess.
        $sessionUuid = method_exists($response, 'getSessionUuid') ? $response->getSessionUuid() : null;

        if (!is_string($sessionUuid) || $sessionUuid === '') {
            throw new VerifierException('Failed to get a valid Plivo session ID.');
        }

        return $sessionUuid;
    }

    protected function checkCode(Challenge $challenge, string $code): bool
    {
        try {
            $response = $this->sessions()->validate($challenge->reference, $code);
        } catch (PlivoResponseException $e) {
            if (in_array((int) $e->getStatusCode(), self::REJECTED_HTTP, true)) {
                return false;
            }

            throw new VerifierException('Plivo could not validate the session: '.$e->getMessage(), 0, $e);
        } catch (\Throwable $e) {
            throw new VerifierException('Plivo could not validate the session: '.$e->getMessage(), 0, $e);
        }

        return stripos((string) $response->getMessage(), 'validated successfully') !== false;
    }

    private function sessions(): VerifySessionInterface
    {
        return $this->sessions ?? $this->client->verifySessions;
    }

    private function sessionArgs(): array
    {
        if ($this->options === []) {
            return (array) config('sms-verification.plivo.optional_args', []);
        }

        return array_filter([
            'app_uuid' => $this->options['app_uuid'] ?? null,
            'channel' => $this->options['channel'] ?? null,
            'locale' => $this->options['locale'] ?? null,
            'brand_name' => $this->options['brand_name'] ?? null,
            'code_length' => isset($this->options['code_length']) ? (int) $this->options['code_length'] : null,
        ], static fn ($value) => $value !== null && $value !== '');
    }
}
