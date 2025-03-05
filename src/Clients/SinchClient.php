<?php

namespace Kwidoo\SmsVerification\Clients;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Response;

class SinchClient
{
    protected $client;
    public function __construct(protected string $appKey, protected string $appSecret, protected string $verificationUrl)
    {
        $this->client = Http::withBasicAuth($this->appKey, $this->appSecret);
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
