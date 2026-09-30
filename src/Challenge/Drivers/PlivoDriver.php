<?php

namespace Kwidoo\SmsVerification\Challenge\Drivers;

use Kwidoo\SmsVerification\Challenge\ChallengeRuntime;
use Kwidoo\SmsVerification\Challenge\Drivers\Concerns\DriverSupport;
use Kwidoo\SmsVerification\Contracts\ChallengeDriver;
use Kwidoo\SmsVerification\Contracts\ChallengeVerifierInterface;
use Kwidoo\SmsVerification\Verifiers\PlivoVerifier;
use Plivo\Authentication\BasicAuth;
use Plivo\BaseClient;
use Plivo\HttpClients\PlivoGuzzleHttpClient;
use Plivo\Resources\Verify\VerifySessionInterface;
use Plivo\RestClient;

/**
 * `plivo`: Plivo Verify sessions. Plivo generates and checks the code.
 */
final class PlivoDriver implements ChallengeDriver
{
    use DriverSupport;

    public const NAME = 'plivo';

    public function name(): string
    {
        return self::NAME;
    }

    public function make(array $config, ChallengeRuntime $runtime): ChallengeVerifierInterface
    {
        $authId = $this->required($config, 'auth_id');
        $authToken = $this->required($config, 'auth_token');

        // RestClient builds its own Guzzle client; the sessions are built on
        // a BaseClient that sends through the host handler instead.
        $base = new BaseClient($authId, $authToken);
        $base->setHttpClientHandler(new PlivoGuzzleHttpClient($this->guzzle($runtime), new BasicAuth($authId, $authToken)));

        return new PlivoVerifier(
            new RestClient($authId, $authToken),
            array_filter([
                'app_uuid' => $this->string($config, 'app_uuid'),
                'channel' => $this->string($config, 'channel', 'sms'),
                'locale' => $this->string($config, 'locale'),
                'brand_name' => $this->string($config, 'brand_name'),
                'code_length' => $this->integer($config, 'code_length'),
                'ttl' => $this->integer($config, 'ttl', PlivoVerifier::DEFAULT_TTL),
            ], static fn ($value) => $value !== null),
            new VerifySessionInterface($base, $authId),
        );
    }

    public function options(): array
    {
        return [
            self::option('auth_id', 'Plivo auth ID', 'Auth ID from the Plivo console.', required: true),
            self::option('auth_token', 'Plivo auth token', 'Auth token from the Plivo console.', required: true, sensitive: true),
            self::option('app_uuid', 'Verify application UUID', 'Plivo Verify application; the account default without it.'),
            self::option('channel', 'Channel', 'sms or voice.', default: 'sms'),
            self::option('locale', 'Locale', 'Language of the message, e.g. en.'),
            self::option('brand_name', 'Brand name', 'Brand shown in the message.'),
            self::option('code_length', 'Code length', 'Digits of the Plivo code (4 to 8).', 'integer'),
            $this->ttlOption(PlivoVerifier::DEFAULT_TTL),
        ];
    }

    public function redaction(): array
    {
        // The code travels as {"otp": "..."}; `otp` is a common sensitive word.
        return ['words' => ['otp']];
    }
}
