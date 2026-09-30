<?php

namespace Kwidoo\SmsVerification\Challenge;

/**
 * What the host gives a driver besides the provider configuration.
 *
 * - `httpHandler`: a Guzzle handler callable. Drivers hand it to the provider
 *   SDK so its HTTP goes through the host's own transport (logging, timeouts,
 *   TLS, call reporting). Null uses Guzzle's default handler.
 * - `codeKey`: secret for drivers that generate the code themselves and seal
 *   it into the challenge (see {@see OtpHasher}). Drivers whose provider owns
 *   the code ignore it.
 * - `timeout`: seconds for provider calls.
 */
final class ChallengeRuntime
{
    /**
     * @param  callable|null  $httpHandler
     */
    public function __construct(
        public readonly mixed $httpHandler = null,
        public readonly ?string $codeKey = null,
        public readonly float $timeout = 10.0,
    ) {}
}
