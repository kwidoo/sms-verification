<?php

namespace Kwidoo\SmsVerification\Exceptions;

/**
 * The verifier cannot be built from the configuration it was given: an
 * unknown driver, a missing credential, an unusable value. Nothing was sent
 * to the provider.
 */
class ConfigurationException extends VerifierException
{
    public static function unknownDriver(string $name, array $known): self
    {
        return new self(sprintf('Unknown verification driver [%s]. Available: %s.', $name, implode(', ', $known)));
    }

    public static function missing(string $driver, string $key): self
    {
        return new self(sprintf('Verification driver [%s] requires [%s].', $driver, $key));
    }
}
