<?php

namespace Kwidoo\SmsVerification\Challenge\Drivers;

use Kwidoo\SmsVerification\Challenge\ChallengeRuntime;
use Kwidoo\SmsVerification\Challenge\Drivers\Concerns\DriverSupport;
use Kwidoo\SmsVerification\Challenge\OtpGenerator;
use Kwidoo\SmsVerification\Clients\SevenHttpClient;
use Kwidoo\SmsVerification\Contracts\ChallengeDriver;
use Kwidoo\SmsVerification\Contracts\ChallengeVerifierInterface;
use Kwidoo\SmsVerification\Verifiers\SevenVerifier;
use Seven\Api\Client;

/**
 * `seven`: seven.io SMS. seven.io only delivers text, so the driver generates
 * the code and seals it into the challenge (needs ChallengeRuntime::$codeKey).
 */
final class SevenDriver implements ChallengeDriver
{
    use DriverSupport;

    public const NAME = 'seven';

    public const DEFAULT_URL = Client::BASE_URI;

    public function name(): string
    {
        return self::NAME;
    }

    public function make(array $config, ChallengeRuntime $runtime): ChallengeVerifierInterface
    {
        return new SevenVerifier(
            new SevenHttpClient($this->required($config, 'api_key'), $runtime->httpHandler, $this->url($config), $runtime->timeout),
            $this->hasher($runtime),
            new OtpGenerator(),
            [
                'message' => $this->string($config, 'message', SevenVerifier::DEFAULT_MESSAGE),
                'code_length' => $this->integer($config, 'code_length', SevenVerifier::DEFAULT_CODE_LENGTH),
                'ttl' => $this->integer($config, 'ttl', SevenVerifier::DEFAULT_TTL),
                'from' => $this->string($config, 'from'),
            ],
        );
    }

    public function options(): array
    {
        return [
            self::option('api_key', 'seven.io API key', 'API key from the seven.io dashboard.', required: true, sensitive: true),
            self::option('from', 'Sender', 'Sender ID or number shown to the recipient.'),
            $this->urlOption('seven.io API'),
            self::option('message', 'SMS text', 'Message text; :code is replaced with the one-time code.', default: SevenVerifier::DEFAULT_MESSAGE),
            self::option('code_length', 'Code length', 'Number of digits, 4 to 10.', 'integer', default: SevenVerifier::DEFAULT_CODE_LENGTH),
            $this->ttlOption(SevenVerifier::DEFAULT_TTL),
        ];
    }

    public function redaction(): array
    {
        // The SMS text carries the code; it travels form-encoded as `text`.
        return ['patterns' => ['/(?:^|[?&])text=([^&\s"]+)/'], 'words' => ['mac']];
    }
}
