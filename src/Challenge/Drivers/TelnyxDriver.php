<?php

namespace Kwidoo\SmsVerification\Challenge\Drivers;

use Kwidoo\SmsVerification\Challenge\ChallengeRuntime;
use Kwidoo\SmsVerification\Challenge\Drivers\Concerns\DriverSupport;
use Kwidoo\SmsVerification\Clients\TelnyxVerifyClient;
use Kwidoo\SmsVerification\Contracts\ChallengeDriver;
use Kwidoo\SmsVerification\Contracts\ChallengeVerifierInterface;
use Kwidoo\SmsVerification\Verifiers\TelnyxVerifier;

/**
 * `telnyx`: Telnyx Verify v2. Telnyx generates and checks the code.
 */
final class TelnyxDriver implements ChallengeDriver
{
    use DriverSupport;

    public const NAME = 'telnyx';

    public const DEFAULT_URL = TelnyxVerifyClient::DEFAULT_URL;

    public function name(): string
    {
        return self::NAME;
    }

    public function make(array $config, ChallengeRuntime $runtime): ChallengeVerifierInterface
    {
        return new TelnyxVerifier(
            new TelnyxVerifyClient($this->required($config, 'api_key'), $this->url($config), $runtime->httpHandler, $runtime->timeout),
            [
                'verify_profile_id' => $this->required($config, 'verify_profile_id'),
                'ttl' => $this->integer($config, 'ttl', TelnyxVerifier::DEFAULT_TTL),
            ],
        );
    }

    public function options(): array
    {
        return [
            self::option('api_key', 'Telnyx API key', 'API key (v2) from the Telnyx portal.', required: true, sensitive: true),
            self::option('verify_profile_id', 'Verify profile ID', 'UUID of the Telnyx Verify profile.', required: true),
            $this->urlOption('Telnyx API'),
            $this->ttlOption(TelnyxVerifier::DEFAULT_TTL),
        ];
    }

    public function redaction(): array
    {
        // The code travels as {"code": "..."}; not redacted, see VonageDriver::redaction().
        return [];
    }
}
