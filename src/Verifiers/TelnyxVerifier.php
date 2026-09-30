<?php

namespace Kwidoo\SmsVerification\Verifiers;

use Kwidoo\SmsVerification\Challenge\Challenge;
use Kwidoo\SmsVerification\Clients\TelnyxVerifyClient;
use Kwidoo\SmsVerification\Contracts\ChallengeVerifierInterface;
use Kwidoo\SmsVerification\Contracts\VerifierInterface;
use Kwidoo\SmsVerification\Exceptions\VerifierException;
use Kwidoo\SmsVerification\Verifiers\Concerns\ProviderOwnedChallenges;

/**
 * Telnyx Verify v2: Telnyx generates, delivers and checks the code.
 *
 * Challenge reference = the verification id; the check is
 * POST /verifications/{id}/actions/verify, `response_code` accepted | rejected.
 */
class TelnyxVerifier extends Verifier implements VerifierInterface, ChallengeVerifierInterface
{
    use ProviderOwnedChallenges;

    public const DEFAULT_TTL = 300;

    /** A check on an expired, used-up or unknown verification is a verdict. */
    private const REJECTED_HTTP = [400, 404, 410, 422];

    protected TelnyxVerifyClient $client;

    /**
     * @param  TelnyxVerifyClient|null  $client  Null builds one from the 1.x config (`telnyx.api_key`).
     * @param  array{verify_profile_id?: string, ttl?: int}  $options  Without `verify_profile_id` the 1.x config value (`telnyx.verify_sid`) is used.
     */
    public function __construct(?TelnyxVerifyClient $client = null, protected array $options = [])
    {
        $this->client = $client ?? new TelnyxVerifyClient((string) config('sms-verification.telnyx.api_key'));
    }

    public function create(string $phoneNumber): void
    {
        $number = $this->sanitizePhoneNumber($phoneNumber);

        cache()->put("telnyx$number", $this->startVerification($number), now()->addMinutes(5));
    }

    public function validate(array $credentials): bool
    {
        if (count($credentials) < 2) {
            throw new VerifierException('Credentials array must include [phoneNumber, code].');
        }

        [$phoneNumber, $verificationCode] = $credentials;
        $number = $this->sanitizePhoneNumber($phoneNumber);

        $verificationId = cache()->pull("telnyx$number");

        if (!$verificationId) {
            throw new VerifierException('No Telnyx verification request found for this number.');
        }

        // 1.x never submitted the code and compared `!$status === 'accepted'`
        // (always false), so every code passed.
        if (!$this->checkCode(new Challenge($number, $verificationId), trim((string) $verificationCode))) {
            throw new VerifierException('Invalid verification code');
        }

        return true;
    }

    protected function startVerification(string $number): string
    {
        [$status, $body] = $this->client->sendSms($number, $this->profileId(), isset($this->options['ttl']) ? (int) $this->options['ttl'] : null);

        $id = $body['data']['id'] ?? null;

        if ($status >= 300 || !is_string($id) || $id === '') {
            throw new VerifierException(sprintf('Telnyx refused the verification: HTTP %d %s', $status, $this->errors($body)));
        }

        return $id;
    }

    protected function checkCode(Challenge $challenge, string $code): bool
    {
        [$status, $body] = $this->client->verifyById((string) $challenge->reference, $code);

        if ($status < 300) {
            return ($body['data']['response_code'] ?? null) === 'accepted';
        }

        if (in_array($status, self::REJECTED_HTTP, true)) {
            return false;
        }

        throw new VerifierException(sprintf('Telnyx could not check the code: HTTP %d %s', $status, $this->errors($body)));
    }

    private function profileId(): string
    {
        $profile = $this->options['verify_profile_id'] ?? config('sms-verification.telnyx.verify_sid');

        if (!$profile) {
            throw new VerifierException('Telnyx verify profile is not configured.');
        }

        return (string) $profile;
    }

    private function errors(array $body): string
    {
        return implode('; ', array_map(
            static fn ($error) => is_array($error) ? trim(($error['code'] ?? '').' '.($error['title'] ?? '').' '.($error['detail'] ?? '')) : (string) $error,
            (array) ($body['errors'] ?? [])
        ));
    }
}
