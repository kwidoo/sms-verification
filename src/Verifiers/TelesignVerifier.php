<?php

namespace Kwidoo\SmsVerification\Verifiers;

use Kwidoo\SmsVerification\Contracts\VerifierInterface;
use Kwidoo\SmsVerification\Exceptions\VerifierException;
use Kwidoo\SmsVerification\Verifiers\Verifier;
use telesign\sdk\messaging\MessagingClient;

class TelesignVerifier extends Verifier implements VerifierInterface
{
    public function __construct(protected MessagingClient $client) {}

    public function create(string $phoneNumber): void
    {
        $number = $this->sanitizePhoneNumber($phoneNumber);

        $verifyCode = rand(1000, 9999);
        $message = "Your code is $verifyCode";

        $response = $this->client->message($number, $message, "OTP");

        if (!$response) {
            throw new VerifierException('Failed to get a valid Telesign request ID.');
        }

        cache()->put("telesign$number", $verifyCode, now()->addMinutes(5));
    }

    public function validate(array $credentials): bool
    {
        if (count($credentials) < 2) {
            throw new VerifierException('Credentials array must include [phoneNumber, code].');
        }

        [$phoneNumber, $verificationCode] = $credentials;
        $number = $this->sanitizePhoneNumber($phoneNumber);

        $code = cache()->pull("telesign$number");
        if (!$code) {
            throw new VerifierException('No Telesign verification request found for this number.');
        }

        if ($code !== $verificationCode) {
            throw new VerifierException('Invalid verification code');
        }


        return true;
    }
}
