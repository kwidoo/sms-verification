<?php

namespace Kwidoo\SmsVerification\Verifiers;

use Kwidoo\SmsVerification\Contracts\VerifierInterface;
use Kwidoo\SmsVerification\Exceptions\VerifierException;
use Telnyx\Telnyx;
use Telnyx\Verification;

class TelnyxVerifier extends Verifier implements VerifierInterface
{
    public function __construct()
    {
        Telnyx::setApiKey(config('sms-verification.telnyx.api_key'));
        Telnyx::$apiBase = 'https://api.telnyx.com';
    }

    /**
     * @param string $phoneNumber Phone number for Telnyx verification
     *
     * @return void
     */
    public function create(string $phoneNumber): void
    {
        $number = $this->sanitizePhoneNumber($phoneNumber);

        try {
            $response = Verification::create([
                'verify_profile_id' => config('sms-verification.telnyx.verify_sid'),
                'phone_number' => $number,
                'type' => 'sms'
            ]);

            if (!$response || !$response->id) {
                throw new VerifierException('Failed to send verification SMS via Telnyx.');
            }

            cache()->put("telnyx$number", $response->id, now()->addMinutes(5));
        } catch (\Exception $e) {
            throw new VerifierException('Telnyx API error: ' . $e->getMessage());
        }
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

        try {
            $verificationId = cache()->pull("telnyx$number");
            if (!$verificationId) {
                throw new VerifierException('No Telnyx verification request found for this number.');
            }

            $opts = [
                'phone_number' => $number,
                'verify_profile_id' => config('sms-verification.telnyx.verify_sid'),
                'code' => $verificationCode,
            ];
            $response = Verification::retrieve($verificationId, $opts);

            if (!$response || !$response->status === 'accepted') {
                throw new VerifierException('Invalid verification code');
            }

            return true;
        } catch (\Exception $e) {
            throw new VerifierException('Telnyx API error: ' . $e->getMessage());
        }
    }
}
