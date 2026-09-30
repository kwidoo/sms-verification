<?php

namespace Kwidoo\SmsVerification\Verifiers;

use Kwidoo\SmsVerification\Challenge\Challenge;
use Kwidoo\SmsVerification\Contracts\ChallengeVerifierInterface;
use Kwidoo\SmsVerification\Contracts\VerifierInterface;
use Kwidoo\SmsVerification\Exceptions\VerifierException;
use Kwidoo\SmsVerification\Verifiers\Concerns\ProviderOwnedChallenges;
use Vonage\Client;
use Vonage\Client\Exception\RequestException;
use Vonage\Verify2\Request\SMSRequest;
use Vonage\Verify2\VerifyObjects\VerificationLocale;

/**
 * Vonage Verify v2: Vonage generates, delivers and checks the code.
 *
 * Challenge reference = the Verify request_id.
 */
class VonageVerifier extends Verifier implements VerifierInterface, ChallengeVerifierInterface
{
    use ProviderOwnedChallenges;

    public const DEFAULT_TTL = 300;

    /**
     * Check outcomes that are a verdict, not a failure: 400 invalid code,
     * 404 request not found (expired or completed), 410 too many attempts or
     * expired.
     */
    private const REJECTED_HTTP = [400, 404, 410];

    /**
     * @param  array{brand?: string, locale?: ?string, ttl?: int}  $options  Without `brand` the 1.x config value is used.
     */
    public function __construct(
        protected Client $client,
        protected array $options = [],
    ) {}

    public function create(string $phoneNumber): void
    {
        $number = $this->sanitizePhoneNumber($phoneNumber);
        $newRequest = new SMSRequest($number, $this->brand());
        $response = $this->client->verify2()->startVerification($newRequest);

        if (!isset($response['request_id'])) {
            throw new VerifierException('Failed to get a valid Vonage request ID.');
        }

        cache()->put("vonage$number", $response['request_id'], now()->addMinutes(5));
    }

    public function validate(array $credentials): bool
    {
        if (count($credentials) < 2) {
            throw new VerifierException('Credentials array must include [phoneNumber, code].');
        }

        [$phoneNumber, $verificationCode] = $credentials;
        $number = $this->sanitizePhoneNumber($phoneNumber);

        $requestId = cache()->pull("vonage$number");
        if (!$requestId) {
            throw new VerifierException('No Vonage verification request found for this number.');
        }

        $response = $this->client->verify2()->check($requestId, $verificationCode);
        if (!$response) {
            throw new VerifierException('Invalid verification code');
        }

        return true;
    }

    protected function startVerification(string $number): string
    {
        $locale = !empty($this->options['locale']) ? new VerificationLocale((string) $this->options['locale']) : null;

        try {
            // Vonage wants E.164 without the leading +.
            $response = $this->client->verify2()->startVerification(
                new SMSRequest(ltrim($number, '+'), $this->brand(), $locale)
            );
        } catch (\Throwable $e) {
            throw new VerifierException('Vonage refused the verification: '.$e->getMessage(), 0, $e);
        }

        $requestId = is_array($response) ? ($response['request_id'] ?? null) : null;

        if (!is_string($requestId) || $requestId === '') {
            throw new VerifierException('Failed to get a valid Vonage request ID.');
        }

        return $requestId;
    }

    protected function checkCode(Challenge $challenge, string $code): bool
    {
        try {
            return $this->client->verify2()->check($challenge->reference, $code);
        } catch (RequestException $e) {
            if (in_array($e->getCode(), self::REJECTED_HTTP, true)) {
                return false;
            }

            throw new VerifierException('Vonage could not check the code: '.$e->getMessage(), 0, $e);
        } catch (\Throwable $e) {
            throw new VerifierException('Vonage could not check the code: '.$e->getMessage(), 0, $e);
        }
    }

    private function brand(): string
    {
        return (string) ($this->options['brand'] ?? config('sms-verification.vonage.brand', 'MyApp'));
    }
}
