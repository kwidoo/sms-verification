<?php

namespace Kwidoo\SmsVerification\Challenge;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Seals a generated code into challenge state and checks a candidate against it.
 *
 * The state holds a random salt and an HMAC-SHA256 over the code, the
 * recipient, the provider reference and the expiry, keyed with a secret that
 * never leaves the verifier. Whoever stores the state can neither read the
 * code nor brute force it offline, and cannot move the state onto another
 * recipient, reference or expiry without invalidating it.
 */
class OtpHasher
{
    public const VERSION = 1;

    public const ALGORITHM = 'HS256';

    private const SALT_BYTES = 16;

    public function __construct(private readonly string $key)
    {
        if (strlen($key) < 16) {
            throw new InvalidArgumentException('The code key must be at least 16 bytes.');
        }
    }

    /**
     * Accepts Laravel's `base64:` key notation.
     */
    public static function fromKey(?string $key): ?self
    {
        if ($key === null || $key === '') {
            return null;
        }

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            $key = $decoded === false ? $key : $decoded;
        }

        return new self($key);
    }

    /**
     * @return array{v: int, alg: string, salt: string, len: int, mac: string}
     */
    public function seal(string $code, string $recipient, ?string $reference, ?DateTimeImmutable $expiresAt): array
    {
        $salt = base64_encode(random_bytes(self::SALT_BYTES));

        return [
            'v' => self::VERSION,
            'alg' => self::ALGORITHM,
            'salt' => $salt,
            'len' => strlen($code),
            'mac' => $this->mac($salt, $code, $recipient, $reference, $expiresAt),
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public function matches(array $state, string $code, string $recipient, ?string $reference, ?DateTimeImmutable $expiresAt): bool
    {
        // Lenient on the version's type: the state may have round-tripped
        // through storage that turns numbers into strings.
        if ((int) ($state['v'] ?? 0) !== self::VERSION || ($state['alg'] ?? null) !== self::ALGORITHM) {
            return false;
        }

        if (!is_string($state['salt'] ?? null) || !is_string($state['mac'] ?? null)) {
            return false;
        }

        if (isset($state['len']) && (int) $state['len'] !== strlen($code)) {
            return false;
        }

        return hash_equals(
            $state['mac'],
            $this->mac($state['salt'], $code, $recipient, $reference, $expiresAt)
        );
    }

    private function mac(string $salt, string $code, string $recipient, ?string $reference, ?DateTimeImmutable $expiresAt): string
    {
        $message = implode("\n", [
            'kwidoo/sms-verification/otp/v'.self::VERSION,
            $salt,
            $recipient,
            (string) $reference,
            // A timestamp, not a formatted date: the challenge may come back
            // rendered in another timezone.
            $expiresAt === null ? '' : (string) $expiresAt->getTimestamp(),
            $code,
        ]);

        return base64_encode(hash_hmac('sha256', $message, $this->key, true));
    }
}
