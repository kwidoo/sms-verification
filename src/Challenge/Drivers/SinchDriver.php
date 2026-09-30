<?php

namespace Kwidoo\SmsVerification\Challenge\Drivers;

use Kwidoo\SmsVerification\Challenge\ChallengeRuntime;
use Kwidoo\SmsVerification\Challenge\Drivers\Concerns\DriverSupport;
use Kwidoo\SmsVerification\Clients\SinchClient;
use Kwidoo\SmsVerification\Contracts\ChallengeDriver;
use Kwidoo\SmsVerification\Contracts\ChallengeVerifierInterface;
use Kwidoo\SmsVerification\Verifiers\SinchVerifier;

/**
 * `sinch`: Sinch Verification. Sinch generates and checks the code.
 */
final class SinchDriver implements ChallengeDriver
{
    use DriverSupport;

    public const NAME = 'sinch';

    public const DEFAULT_URL = 'https://verification.api.sinch.com';

    public function name(): string
    {
        return self::NAME;
    }

    public function make(array $config, ChallengeRuntime $runtime): ChallengeVerifierInterface
    {
        return new SinchVerifier(
            new SinchClient(
                $this->required($config, 'application_key'),
                $this->required($config, 'application_secret'),
                $this->url($config),
                $runtime->httpHandler,
                $runtime->timeout,
            ),
            ['ttl' => $this->integer($config, 'ttl', SinchVerifier::DEFAULT_TTL)],
        );
    }

    public function options(): array
    {
        return [
            self::option('application_key', 'Sinch application key', 'Verification application key from the Sinch dashboard.', required: true),
            self::option('application_secret', 'Sinch application secret', 'Verification application secret.', required: true, sensitive: true),
            $this->urlOption('Sinch Verification API'),
            $this->ttlOption(SinchVerifier::DEFAULT_TTL),
        ];
    }

    public function redaction(): array
    {
        // The code travels as {"sms": {"code": "..."}}; not redacted, see VonageDriver::redaction().
        return [];
    }
}
