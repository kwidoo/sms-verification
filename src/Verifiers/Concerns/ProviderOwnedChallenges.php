<?php

namespace Kwidoo\SmsVerification\Verifiers\Concerns;

use DateTimeImmutable;
use Kwidoo\SmsVerification\Challenge\Challenge;
use Kwidoo\SmsVerification\Exceptions\VerifierException;

/**
 * ChallengeVerifierInterface for providers that generate, deliver and check
 * the code themselves: the challenge is the provider's reference only.
 *
 * The using class implements startVerification() (returns the reference) and
 * checkCode() (a wrong, expired or used-up code is `false`, not an error), and
 * may define DEFAULT_TTL.
 */
trait ProviderOwnedChallenges
{
    /**
     * @throws VerifierException
     */
    abstract protected function startVerification(string $number): string;

    /**
     * @throws VerifierException
     */
    abstract protected function checkCode(Challenge $challenge, string $code): bool;

    public function dispatch(string $recipient): Challenge
    {
        $number = $this->sanitizePhoneNumber($recipient);

        return new Challenge(
            recipient: $number,
            reference: $this->startVerification($number),
            state: [],
            expiresAt: new DateTimeImmutable(sprintf('+%d seconds', $this->challengeTtl())),
        );
    }

    public function verify(Challenge $challenge, string $code): bool
    {
        $code = $this->normalizeOtp($code);

        if ($code === null || $challenge->isExpired()) {
            return false;
        }

        if ($challenge->reference === null || $challenge->reference === '') {
            throw new VerifierException(sprintf('%s challenge has no provider reference.', class_basename(static::class)));
        }

        return $this->checkCode($challenge, $code);
    }

    protected function challengeTtl(): int
    {
        $default = defined(static::class.'::DEFAULT_TTL') ? (int) constant(static::class.'::DEFAULT_TTL') : 300;

        return max(1, (int) ($this->options['ttl'] ?? $default));
    }

    /**
     * A code as typed by a user: trimmed, digits only, 3 to 10 of them.
     */
    protected function normalizeOtp(string $code): ?string
    {
        $code = trim($code);

        return ctype_digit($code) && strlen($code) >= 3 && strlen($code) <= 10 ? $code : null;
    }
}
