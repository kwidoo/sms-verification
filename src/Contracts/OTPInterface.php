<?php

namespace Kwidoo\SmsVerifications\Contracts;

interface OTPInterface
{
    public function create(string $username): void;
    public function validate(array $credentials): bool;
}
