<?php

namespace Kwidoo\SmsVerification\Challenge\Drivers;

use Kwidoo\SmsVerification\Challenge\ChallengeRuntime;
use Kwidoo\SmsVerification\Challenge\Drivers\Concerns\DriverSupport;
use Kwidoo\SmsVerification\Contracts\ChallengeDriver;
use Kwidoo\SmsVerification\Contracts\ChallengeVerifierInterface;
use Kwidoo\SmsVerification\Verifiers\VonageVerifier;
use Vonage\Client;
use Vonage\Client\Credentials\Basic;

/**
 * `vonage`: Vonage Verify v2. Vonage generates and checks the code.
 */
final class VonageDriver implements ChallengeDriver
{
    use DriverSupport;

    public const NAME = 'vonage';

    public function name(): string
    {
        return self::NAME;
    }

    public function make(array $config, ChallengeRuntime $runtime): ChallengeVerifierInterface
    {
        $client = new Client(
            new Basic($this->required($config, 'api_key'), $this->required($config, 'api_secret')),
            [],
            $this->guzzle($runtime),
        );

        return new VonageVerifier($client, [
            'brand' => $this->required($config, 'brand'),
            'locale' => $this->string($config, 'locale'),
            'ttl' => $this->integer($config, 'ttl', VonageVerifier::DEFAULT_TTL),
        ]);
    }

    public function options(): array
    {
        return [
            self::option('api_key', 'Vonage API key', 'API key from the Vonage dashboard.', required: true),
            self::option('api_secret', 'Vonage API secret', 'API secret from the Vonage dashboard.', required: true, sensitive: true),
            self::option('brand', 'Brand', 'Name shown in the Verify message.', required: true),
            self::option('locale', 'Locale', 'Language of the Verify message, e.g. en-us.'),
            $this->ttlOption(VonageVerifier::DEFAULT_TTL),
        ];
    }

    public function redaction(): array
    {
        // The code travels as {"code": "..."}. Not redacted: a rule on the key
        // would also mask every `status.code`, and the code is short-lived and
        // single-use.
        return [];
    }
}
