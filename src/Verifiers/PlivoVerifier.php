<?php

namespace Kwidoo\SmsVerification\Verifiers;

use Kwidoo\SmsVerification\Contracts\VerifierInterface;
use Kwidoo\SmsVerification\Exceptions\VerifierException;
use Kwidoo\SmsVerification\Verifiers\Verifier;
use Plivo\RestClient as Client;

class PlivoVerifier extends Verifier implements VerifierInterface
{
    public function __construct(protected Client $client) {}

    /**
     * @param string $phoneNumber Phone number for Vonage verification
     *
     * @return void
     */
    public function create(string $phoneNumber): void
    {
        $number = $this->sanitizePhoneNumber($phoneNumber);

        $optionalArgs = config('sms-verification.plivo.optional_args', []);


        $response = $this->client->verifySessions->create($number, $optionalArgs);

        if (!isset($response['session_uuid'])) {
            throw new VerifierException('Failed to get a valid Plivo session ID.');
        }

        cache()->put("plivo$number", $response['session_uuid'], now()->addMinutes(5));
    }

    /**
     * @param array $credentials [phone number, verification code]
     *
     * @return bool
     */
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

        $response = $this->client->verifySessions->validate($sessionId, $verificationCode);

        if (!$response || $response['message'] !== 'session validated successfully.') {
            throw new VerifierException('Invalid verification code');
        }

        return true;
    }
}
