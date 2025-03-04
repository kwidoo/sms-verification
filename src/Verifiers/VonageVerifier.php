<?php

namespace Kwidoo\SmsVerification\Verifiers;

use Kwidoo\SmsVerification\Contracts\VerifierInterface;
use Kwidoo\SmsVerification\Exceptions\VerifierException;
use Kwidoo\SmsVerification\Verifiers\Verifier;
use Vonage\Client;
use Vonage\Verify2\Request\SMSRequest;

class VonageVerifier extends Verifier implements VerifierInterface
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

        $newRequest = new SMSRequest($number, config('sms-verification.vonage.brand', 'MyApp'));
        $response = $this->client->verify2()->startVerification($newRequest);

        if (!isset($response['request_id'])) {
            throw new VerifierException('Failed to get a valid Vonage request ID.');
        }

        cache()->put("vonage$number", $response['request_id'], now()->addMinutes(5));
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
}
