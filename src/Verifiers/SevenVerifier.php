<?php

namespace Kwidoo\SmsVerification\Verifiers;

use Kwidoo\SmsVerification\Contracts\VerifierInterface;
use Kwidoo\SmsVerification\Exceptions\VerifierException;
use Seven\Api\Client;
use Seven\Api\Resource\Sms\SmsParams;
use Seven\Api\Resource\Sms\SmsResource;

class SevenVerifier extends Verifier implements VerifierInterface
{
    public function __construct(protected Client $client) {}

    /**
     * @param string $phoneNumber Phone number for Telnyx verification
     *
     * @return void
     */
    public function create(string $phoneNumber): void
    {
        $number = $this->sanitizePhoneNumber($phoneNumber);

        $verifyCode = rand(1000, 9999);
        $message = "Your code is $verifyCode";

        $smsResource = new SmsResource($this->client);
        $smsParams = new SmsParams($message, $number);
        $response = $smsResource->dispatch($smsParams);

        if (!$response || $response->getSuccess() !== 100) {
            throw new VerifierException('Failed to get a valid Seven.io request.');
        }

        cache()->put("sevenio$number", $verifyCode, now()->addMinutes(5));
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

        $code = cache()->pull("sevenio$number");
        if (!$code) {
            throw new VerifierException('No Seven.io verification request found for this number.');
        }

        if ((int)$code !== (int)$verificationCode) {
            throw new VerifierException('Invalid verification code');
        }

        return true;
    }
}
