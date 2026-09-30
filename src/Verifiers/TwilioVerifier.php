<?php

namespace Kwidoo\SmsVerification\Verifiers;

use Kwidoo\SmsVerification\Challenge\Challenge;
use Kwidoo\SmsVerification\Contracts\ChallengeVerifierInterface;
use Kwidoo\SmsVerification\Contracts\VerifierInterface;
use Kwidoo\SmsVerification\Exceptions\VerifierException;
use Kwidoo\SmsVerification\Verifiers\Concerns\ProviderOwnedChallenges;
use Twilio\Exceptions\RestException;
use Twilio\Exceptions\TwilioException;
use Twilio\Rest\Client;

/**
 * Twilio Verify v2: Twilio generates, delivers and checks the code.
 *
 * Challenge reference = the verification SID; the check is made by that SID.
 */
class TwilioVerifier extends Verifier implements VerifierInterface, ChallengeVerifierInterface
{
    use ProviderOwnedChallenges;

    /** Twilio Verify codes are valid for 10 minutes by default. */
    public const DEFAULT_TTL = 600;

    /**
     * Check outcomes that are a verdict, not a failure: 404 (20404) - the
     * verification expired, was approved already or does not exist; 429
     * (60202) - max check attempts reached.
     */
    private const REJECTED_HTTP = [404, 429];

    /**
     * @param  array{verify_sid?: string, channel?: string, locale?: ?string, ttl?: int}  $options  Without `verify_sid` the 1.x config value is used.
     */
    public function __construct(
        protected Client $client,
        protected array $options = [],
    ) {}

    /**
     * @param string $phoneNumber Phone number for Twilio verification
     */
    public function create(string $phoneNumber): void
    {
        $number = $this->sanitizePhoneNumber($phoneNumber);

        $this->services()->verifications->create($number, $this->channel(), $this->createOptions());
    }

    /**
     * @param array $credentials [phone number, verification code]
     */
    public function validate(array $credentials): bool
    {
        if (count($credentials) < 2) {
            throw new VerifierException('Credentials array must include [phoneNumber, code].');
        }

        [$phoneNumber, $verificationCode] = $credentials;

        $number = $this->sanitizePhoneNumber($phoneNumber);

        $verification = $this->services()
            ->verificationChecks
            ->create([
                'to' => $number,
                'code' => $verificationCode,
            ]);

        if (!$verification || !$verification->valid) {
            throw new VerifierException('Invalid verification code');
        }

        return true;
    }

    protected function startVerification(string $number): string
    {
        try {
            $verification = $this->services()->verifications->create($number, $this->channel(), $this->createOptions());
        } catch (TwilioException $e) {
            throw new VerifierException('Twilio refused the verification: '.$e->getMessage(), 0, $e);
        }

        if (!is_string($verification->sid ?? null) || $verification->sid === '') {
            throw new VerifierException('Twilio response has no verification SID.');
        }

        return $verification->sid;
    }

    protected function checkCode(Challenge $challenge, string $code): bool
    {
        try {
            $check = $this->services()->verificationChecks->create([
                'verificationSid' => $challenge->reference,
                'code' => $code,
            ]);
        } catch (RestException $e) {
            if (in_array($e->getStatusCode(), self::REJECTED_HTTP, true)) {
                return false;
            }

            throw new VerifierException('Twilio could not check the code: '.$e->getMessage(), 0, $e);
        } catch (TwilioException $e) {
            throw new VerifierException('Twilio could not check the code: '.$e->getMessage(), 0, $e);
        }

        return ($check->status ?? null) === 'approved' || ($check->valid ?? false) === true;
    }

    private function services()
    {
        $verifySid = $this->options['verify_sid'] ?? config('sms-verification.twilio.verify_sid');

        if (!$verifySid) {
            throw new VerifierException('Twilio verify SID is not configured.');
        }

        return $this->client->verify->v2->services($verifySid);
    }

    private function channel(): string
    {
        return (string) ($this->options['channel'] ?? 'sms');
    }

    private function createOptions(): array
    {
        return array_filter(['locale' => $this->options['locale'] ?? null]);
    }
}
