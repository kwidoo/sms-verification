<?php

namespace Kwidoo\SmsVerification\Challenge;

use DateTimeImmutable;
use DateTimeInterface;
use JsonSerializable;
use Kwidoo\SmsVerification\Exceptions\VerifierException;

/**
 * One dispatched verification challenge.
 *
 * Returned by {@see \Kwidoo\SmsVerification\Contracts\ChallengeVerifierInterface::dispatch()}
 * and handed back, unchanged, to verify(). The verifier keeps nothing between
 * the two calls: whatever it needs to reach a verdict travels in this object,
 * so the caller decides where it is persisted.
 *
 * - `reference` is the provider's own identifier for the message or
 *   verification (Telesign reference_id, Twilio verification SID, ...).
 * - `state` is opaque verifier data. It never contains the code itself;
 *   a verifier that generates the code stores a keyed MAC of it here, and a
 *   verifier whose provider owns the code leaves it empty.
 */
final class Challenge implements JsonSerializable
{
    /**
     * @param  array<string, mixed>  $state
     */
    public function __construct(
        public readonly string $recipient,
        public readonly ?string $reference = null,
        public readonly array $state = [],
        public readonly ?DateTimeImmutable $expiresAt = null,
    ) {}

    public function isExpired(?DateTimeInterface $now = null): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        $now ??= new DateTimeImmutable();

        return $now >= $this->expiresAt;
    }

    /**
     * @return array{recipient: string, reference: ?string, state: array<string, mixed>, expires_at: ?string}
     */
    public function toArray(): array
    {
        return [
            'recipient' => $this->recipient,
            'reference' => $this->reference,
            'state' => $this->state,
            'expires_at' => $this->expiresAt?->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws VerifierException
     */
    public static function fromArray(array $data): self
    {
        $recipient = $data['recipient'] ?? null;

        if (!is_string($recipient) || $recipient === '') {
            throw new VerifierException('Challenge recipient is missing.');
        }

        $reference = $data['reference'] ?? null;
        $state = $data['state'] ?? [];
        $expiresAt = $data['expires_at'] ?? null;

        if ($reference !== null && !is_string($reference)) {
            throw new VerifierException('Challenge reference must be a string.');
        }

        if (!is_array($state)) {
            throw new VerifierException('Challenge state must be an object.');
        }

        try {
            $expiresAt = $expiresAt === null || $expiresAt === '' ? null : new DateTimeImmutable((string) $expiresAt);
        } catch (\Exception) {
            throw new VerifierException('Challenge expires_at is not a valid date.');
        }

        return new self($recipient, $reference, $state, $expiresAt);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
