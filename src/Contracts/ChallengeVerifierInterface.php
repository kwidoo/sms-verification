<?php

namespace Kwidoo\SmsVerification\Contracts;

use Kwidoo\SmsVerification\Challenge\Challenge;
use Kwidoo\SmsVerification\Exceptions\VerifierException;

/**
 * Stateless verification: dispatch() returns everything verify() needs.
 *
 * Unlike {@see VerifierInterface}, nothing is written to a cache between the
 * two calls, so the verifier can run in stateless services where the caller
 * persists the challenge (for example a hub that stores it against its own
 * authorization record).
 */
interface ChallengeVerifierInterface
{
    /**
     * Sends a code to the recipient.
     *
     * @throws VerifierException When the provider refuses or cannot be reached.
     */
    public function dispatch(string $recipient): Challenge;

    /**
     * Whether the code answers the challenge. A wrong or expired code is
     * `false`, not an exception.
     *
     * @throws VerifierException When the challenge is malformed or the provider fails.
     */
    public function verify(Challenge $challenge, string $code): bool;
}
