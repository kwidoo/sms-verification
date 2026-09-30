<?php

namespace Kwidoo\SmsVerification\Clients;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Kwidoo\SmsVerification\Exceptions\VerifierException;

/**
 * Minimal Telnyx Verify v2 client over Guzzle.
 *
 * telnyx/telnyx-php keeps its API key and HTTP client in static state, which
 * cannot serve two tenants in one process or send through a host transport;
 * this client takes both per instance.
 */
class TelnyxVerifyClient
{
    public const DEFAULT_URL = 'https://api.telnyx.com/v2';

    private Client $http;

    public function __construct(
        string $apiKey,
        string $baseUrl = self::DEFAULT_URL,
        ?callable $handler = null,
        float $timeout = 10.0,
    ) {
        $this->http = new Client(array_filter([
            'base_uri' => rtrim($baseUrl, '/').'/',
            'handler' => $handler === null ? null : HandlerStack::create($handler),
            'timeout' => $timeout,
            'http_errors' => false,
            'headers' => [
                'Authorization' => 'Bearer '.$apiKey,
                'Accept' => 'application/json',
            ],
        ], static fn ($value) => $value !== null));
    }

    /**
     * POST /verifications/sms
     *
     * @return array{0: int, 1: array<string, mixed>} HTTP status and decoded body
     */
    public function sendSms(string $phoneNumber, string $verifyProfileId, ?int $timeoutSecs = null): array
    {
        return $this->post('verifications/sms', array_filter([
            'phone_number' => $phoneNumber,
            'verify_profile_id' => $verifyProfileId,
            'timeout_secs' => $timeoutSecs,
        ], static fn ($value) => $value !== null));
    }

    /**
     * POST /verifications/{id}/actions/verify
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function verifyById(string $verificationId, string $code): array
    {
        return $this->post('verifications/'.rawurlencode($verificationId).'/actions/verify', ['code' => $code]);
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function post(string $path, array $body): array
    {
        try {
            $response = $this->http->post($path, ['json' => $body]);
        } catch (\Throwable $e) {
            throw new VerifierException('Telnyx request failed: '.$e->getMessage(), 0, $e);
        }

        $decoded = json_decode((string) $response->getBody(), true);

        return [$response->getStatusCode(), is_array($decoded) ? $decoded : []];
    }
}
