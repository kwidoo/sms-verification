<?php

namespace Kwidoo\SmsVerification\Verifiers;

use Kwidoo\SmsVerification\Contracts\VerifierInterface;
use Kwidoo\SmsVerification\Exceptions\VerifierException;
use Kwidoo\SmsVerification\Verifiers\Verifier;
use Kwidoo\SmsVerification\Clients\SinchClient;

class SinchVerifier extends Verifier implements VerifierInterface
{
    public function __construct(protected SinchClient $client) {}

    /**
     * @param string $phoneNumber Phone number for Sinch verification
     *
     * @return void
     */
    public function create(string $phoneNumber): void
    {
        $number = $this->sanitizePhoneNumber($phoneNumber);

        $response = $this->client->sendVerification($number);

        if (!isset($response['id'])) {
            throw new VerifierException('Failed to get a valid Sinch request ID.');
        }
        if (!isset($response['_links'][1]['href'])) {
            throw new VerifierException('Failed to get a valid Sinch verification URL.');
        }

        cache()->put("sinch$number", $response['_links'][1]['href'], now()->addMinutes(5));
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

        $url = cache()->pull("sinch$number");
        if (!$url) {
            throw new VerifierException('No Sinch verification request found for this number.');
        }

        $response = $this->client->check($url, $verificationCode);

        if (!$response || $response['status'] !== 'SUCCESSFUL') {
            throw new VerifierException('Failed to verify the code.');
        }

        return true;
    }
}
