<?php

namespace Kwidoo\SmsVerification\Verifiers\Concerns;

use DateTimeImmutable;
use Kwidoo\SmsVerification\Challenge\Challenge;
use Kwidoo\SmsVerification\Exceptions\VerifierException;

/**
 * ChallengeVerifierInterface for providers that only deliver text: the
 * verifier generates the code, sends it through sendCode(), and seals an
 * HMAC of it into the challenge state. Nothing about the code is stored.
 *
 * The using class provides $hasher (?OtpHasher), $generator (OtpGenerator),
 * $options (message, code_length, ttl) and sendCode(); it may define
 * DEFAULT_CODE_LENGTH and DEFAULT_TTL.
 */
trait SealsCodes
{
    /**
     * Sends the code and returns the provider's message reference.
     *
     * @throws VerifierException
     */
    abstract protected function sendCode(string $number, string $code): string;

    public function dispatch(string $recipient): Challenge
    {
        if ($this->hasher === null) {
            throw new VerifierException(sprintf('%s challenge verification requires a code key.', class_basename(static::class)));
        }

        $number = $this->sanitizePhoneNumber($recipient);
        $code = $this->generator->generate($this->codeLength());
        $reference = $this->sendCode($number, $code);
        $expiresAt = new DateTimeImmutable(sprintf('+%d seconds', $this->ttl()));

        return new Challenge(
            recipient: $number,
            reference: $reference,
            state: $this->hasher->seal($code, $number, $reference, $expiresAt),
            expiresAt: $expiresAt,
        );
    }

    public function verify(Challenge $challenge, string $code): bool
    {
        if ($this->hasher === null) {
            throw new VerifierException(sprintf('%s challenge verification requires a code key.', class_basename(static::class)));
        }

        $code = trim($code);

        if ($code === '' || !ctype_digit($code) || $challenge->isExpired()) {
            return false;
        }

        return $this->hasher->matches(
            $challenge->state,
            $code,
            $this->sanitizePhoneNumber($challenge->recipient),
            $challenge->reference,
            $challenge->expiresAt,
        );
    }

    /** The configured text with `:code` replaced. */
    protected function messageFor(string $code, string $default): string
    {
        return str_replace(':code', $code, (string) ($this->options['message'] ?? $default));
    }

    protected function codeLength(): int
    {
        $default = defined(static::class.'::DEFAULT_CODE_LENGTH') ? (int) constant(static::class.'::DEFAULT_CODE_LENGTH') : 6;

        return (int) ($this->options['code_length'] ?? $default);
    }

    protected function ttl(): int
    {
        $default = defined(static::class.'::DEFAULT_TTL') ? (int) constant(static::class.'::DEFAULT_TTL') : 300;

        return max(1, (int) ($this->options['ttl'] ?? $default));
    }
}
