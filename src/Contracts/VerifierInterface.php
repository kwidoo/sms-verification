<?php

namespace Kwidoo\SmsVerification\Contracts;

interface VerifierInterface
{
    public function create(string $phoneNumber): void;
    public function validate(array $credentials): bool;
}
