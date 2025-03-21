<?php

namespace Kwidoo\SmsVerification\Verifiers;

use Kwidoo\SmsVerification\Contracts\VerifierInterface;
use Kwidoo\SmsVerification\Exceptions\VerifierException;
use Twilio\Rest\Client;

class TwilioVerifier extends Verifier implements VerifierInterface
{
    public function __construct(protected Client $client) {}

    /**
     * @param string $username Phone number for Twilio verification
     *
     * @return void
     */
    public function create(string $phoneNumber): void
    {
        $number = $this->sanitizePhoneNumber($phoneNumber);

        $verifySid = config('sms-verification.twilio.verify_sid');
        if (!$verifySid) {
            throw new VerifierException('Twilio verify SID is not configured.');
        }

        $this->client->verify->v2->services($verifySid)
            ->verifications
            ->create($number, 'sms');
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

        $verifySid = config('sms-verification.twilio.verify_sid');
        if (!$verifySid) {
            throw new VerifierException('Twilio verify SID is not configured.');
        }

        $verification = $this->client
            ->verify
            ->v2
            ->services($verifySid)
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
}
