<?php

namespace Kwidoo\SmsVerification\Verifiers;


use Kwidoo\SmsVerification\Contracts\VerifierInterface;

abstract class Verifier implements VerifierInterface
{
    /**
     * {@inheritdoc}
     */
    abstract public function create(string $username): void;

    /**
     * {@inheritdoc}
     */
    abstract public function validate(array $credentials): bool;

    /**
     * Sanitize the phone number by ensuring only numbers and '+' are present.
     * If the phone number doesn't start with '+', add it.
     *
     * @param string $phoneNumber
     *
     * @return string
     */
    public function sanitizePhoneNumber(string $phoneNumber): string
    {
        $phoneNumber = preg_replace('/[^0-9+]/', '', $phoneNumber);

        if (strpos($phoneNumber, '+') !== 0) {
            $phoneNumber = '+' . $phoneNumber;
        }

        return $phoneNumber;
    }
}
