<?php

namespace Kwidoo\SmsVerification\Challenge\Drivers\Concerns;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Kwidoo\SmsVerification\Challenge\ChallengeRuntime;
use Kwidoo\SmsVerification\Challenge\OtpHasher;
use Kwidoo\SmsVerification\Exceptions\ConfigurationException;

/**
 * Configuration reading and option declaration shared by the drivers.
 *
 * A using driver may define DEFAULT_URL (used by url() and urlOption()).
 */
trait DriverSupport
{
    /**
     * @throws ConfigurationException
     */
    private function required(array $config, string $key): string
    {
        $value = $config[$key] ?? null;

        if (!is_scalar($value) || trim((string) $value) === '') {
            throw ConfigurationException::missing($this->name(), $key);
        }

        return trim((string) $value);
    }

    private function string(array $config, string $key, ?string $default = null): ?string
    {
        $value = $config[$key] ?? null;

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : $default;
    }

    private function integer(array $config, string $key, ?int $default = null): ?int
    {
        $value = $config[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    /** The configured endpoint, or the driver's production default. */
    private function url(array $config): string
    {
        return rtrim((string) $this->string($config, 'url', self::DEFAULT_URL), '/');
    }

    private function handler(ChallengeRuntime $runtime): ?HandlerStack
    {
        return $runtime->httpHandler === null ? null : HandlerStack::create($runtime->httpHandler);
    }

    /** A Guzzle client over the host handler, for SDKs that take one. */
    private function guzzle(ChallengeRuntime $runtime): Client
    {
        return new Client(array_filter([
            'handler' => $this->handler($runtime),
            'timeout' => $runtime->timeout,
        ], static fn ($value) => $value !== null));
    }

    /**
     * @throws ConfigurationException
     */
    private function hasher(ChallengeRuntime $runtime): OtpHasher
    {
        if ($runtime->codeKey === null || $runtime->codeKey === '') {
            throw new ConfigurationException(sprintf('Verification driver [%s] requires a code key.', $this->name()));
        }

        try {
            return new OtpHasher($runtime->codeKey);
        } catch (\InvalidArgumentException $e) {
            throw new ConfigurationException($e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array{key: string, label: string, type: string, required: bool, sensitive: bool, description: string, default?: mixed}
     */
    private static function option(string $key, string $label, string $description, string $type = 'string', bool $required = false, bool $sensitive = false, mixed $default = null): array
    {
        $option = compact('key', 'label', 'type', 'required', 'sensitive', 'description');

        if ($default !== null && !$sensitive) {
            $option['default'] = $default;
        }

        return $option;
    }

    private function urlOption(string $what): array
    {
        return self::option('url', 'API endpoint', sprintf('%s endpoint, default %s.', $what, self::DEFAULT_URL), default: self::DEFAULT_URL);
    }

    private function ttlOption(int $default): array
    {
        return self::option('ttl', 'Code lifetime (seconds)', 'How long the challenge is accepted (its expires_at).', 'integer', default: $default);
    }
}
