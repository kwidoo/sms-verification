<?php

namespace Kwidoo\SmsVerification\Challenge;

use InvalidArgumentException;

/**
 * Numeric one-time codes from a CSPRNG.
 */
class OtpGenerator
{
    public const MIN_LENGTH = 4;

    public const MAX_LENGTH = 10;

    public function generate(int $length = 6): string
    {
        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'Code length must be between %d and %d digits.',
                self::MIN_LENGTH,
                self::MAX_LENGTH
            ));
        }

        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= (string) random_int(0, 9);
        }

        return $code;
    }
}
