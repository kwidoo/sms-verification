<?php

namespace Kwidoo\SmsVerification\Clients;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Response;

class SinchClient
{
    protected $client;

    /**
     * @param  callable|null  $handler  Guzzle handler to send through (e.g. a host transport).
     */
    public function __construct(
        protected string $appKey,
        protected string $appSecret,
        protected string $verificationUrl,
        ?callable $handler = null,
        ?float $timeout = null,
    ) {
        $this->verificationUrl = rtrim($this->verificationUrl, '/');
        $this->client = Http::withBasicAuth($this->appKey, $this->appSecret);

        if ($handler !== null) {
            $this->client->setHandler($handler);
        }

        if ($timeout !== null) {
            $this->client->timeout((int) ceil($timeout));
        }
    }

    public function sendVerification(string $phoneNumber, string $method = 'sms'): Response
    {
        $url = "$this->verificationUrl/verification/v1/verifications";

        $response = $this->client
            ->post($url, [
                'identity' => [
                    'type' => 'number',
                    'endpoint' => $phoneNumber,
                ],
                'method' => $method,
            ]);

        return $response;
    }

    /**
     * Report the code for a verification by its id:
     * PUT /verification/v1/verifications/id/{id}.
     */
    public function reportById(string $id, string $code, string $method = 'sms'): Response
    {
        return $this->check("$this->verificationUrl/verification/v1/verifications/id/".rawurlencode($id), $code, $method);
    }

    public function check(string $url, string $code, string $method = 'sms'): Response
    {
        return $this->client->put($url, [
            'method' => $method,
            'sms' => [
                'code' => $code,
            ],
        ]);
    }
}
