<?php

namespace Kwidoo\SmsVerification\Clients;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\HandlerStack;
use Seven\Api\Client;
use Seven\Api\Exception\ForbiddenIpException;
use Seven\Api\Exception\InvalidApiKeyException;
use Seven\Api\Exception\MissingAccessRightsException;
use Seven\Api\Exception\UnexpectedApiResponseException;
use Seven\Api\Library\HttpMethod;

/**
 * seven.io SDK client that sends over Guzzle instead of raw curl, so a host
 * can pass its own handler (transport, logging) and endpoint. The SDK's
 * resources (SmsResource, ...) work on it unchanged.
 */
class SevenHttpClient extends Client
{
    private Guzzle $http;

    public function __construct(
        string $apiKey,
        ?callable $handler = null,
        private readonly string $baseUrl = Client::BASE_URI,
        float $timeout = 10.0,
        string $sentWith = 'php-api',
    ) {
        parent::__construct($apiKey, $sentWith);

        $this->http = new Guzzle(array_filter([
            'handler' => $handler === null ? null : HandlerStack::create($handler),
            'timeout' => $timeout,
            'http_errors' => false,
        ], static fn ($value) => $value !== null));
    }

    protected function request(string $path, HttpMethod $method, array $payload = []): mixed
    {
        $headers = [];

        foreach (array_unique($this->headers) as $line) {
            [$name, $value] = array_map('trim', explode(':', $line, 2)) + [1 => ''];
            $headers[$name] = $value;
        }

        $url = rtrim($this->baseUrl, '/').'/'.ltrim($path, '/');
        $options = ['headers' => $headers];

        if ($method === HttpMethod::GET) {
            $options['query'] = $payload;
        } else {
            $options['form_params'] = $payload;
        }

        try {
            $response = $this->http->request($method->name, $url, $options);
        } catch (\Throwable $e) {
            throw new UnexpectedApiResponseException($e->getMessage());
        }

        $raw = (string) $response->getBody();

        if ($raw === '900') {
            throw new InvalidApiKeyException;
        }

        $decoded = json_decode($raw, false);
        $res = json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;

        if ($response->getStatusCode() === 200) {
            return $res;
        }

        $code = is_object($res) ? ($res->code ?? $res->error_code ?? null) : (is_numeric($res) ? (int) $res : null);

        throw match ((int) $code) {
            900 => new InvalidApiKeyException,
            902 => new MissingAccessRightsException,
            903 => new ForbiddenIpException,
            default => new UnexpectedApiResponseException(sprintf('HTTP %d %s', $response->getStatusCode(), is_string($res) ? $res : '')),
        };
    }
}
