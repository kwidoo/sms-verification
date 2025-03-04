<?php

namespace Kwidoo\SmsVerification\Verifiers;

use Kwidoo\SmsVerification\Contracts\VerifierInterface;
use Kwidoo\SmsVerification\Exceptions\VerifierException;

class RoundRobinVerifier extends Verifier implements VerifierInterface
{
    public function __construct(protected array $verifiers) {}

    protected function getNextVerifier(): VerifierInterface
    {
        $cacheKey = config('sms-verification.round_robin.current_verifier_cache_key', 'round_robin_verifiers_');

        $currentIndex = cache()->get($cacheKey, 0);

        $verifier = $this->verifiers[$currentIndex];
        $newIndex = ($currentIndex + 1) % count($this->verifiers);


        cache()->put($cacheKey, $newIndex, now()->addMinutes(10));

        return $verifier;
    }

    /**
     * @param string $username Phone number for Twilio verification
     *
     * @return void
     */
    public function create(string $phoneNumber): void
    {
        $cacheKey = config('sms-verification.round_robin.verifier_for_number_cache_key', 'round_robin_verifier_for_');

        $verifier = $this->getNextVerifier();

        $verifierIndex = array_search($verifier, $this->verifiers, true);
        if ($verifierIndex === false) {
            throw new VerifierException('Failed to find the chosen verifier in the verifiers list.');
        }

        $number = $this->sanitizePhoneNumber($phoneNumber);

        $verifier->create($number);

        cache()->put(
            "{$cacheKey}{$number}",
            $verifierIndex,
            now()->addMinutes(10)
        );
    }

    /**
     * @param array $credentials [phone number, verification code]
     *
     * @return bool
     */
    public function validate(array $credentials): bool
    {
        if (count($credentials) < 2) {
            throw new VerifierException('Credentials must include [phoneNumber, verificationCode].');
        }

        $cacheKey = config('sms-verification.round_robin.verifier_for_number_cache_key', 'round_robin_verifier_for_');

        $number = $this->sanitizePhoneNumber($$credentials[0]);

        $verifierIndex = cache()->pull("{$cacheKey}{$number}");

        if ($verifierIndex === null) {
            throw new VerifierException("No verifier index found for phoneNumber: $number");
        }

        $verifier = $this->verifiers[$verifierIndex] ?? null;
        if (!$verifier) {
            throw new VerifierException("Invalid verifier index: $verifierIndex");
        }

        return $verifier->validate($credentials);
    }
}
