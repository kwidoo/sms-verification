<?php

namespace Kwidoo\SmsVerification\Challenge\Drivers;

use Kwidoo\SmsVerification\Challenge\ChallengeRuntime;
use Kwidoo\SmsVerification\Challenge\Drivers\Concerns\DriverSupport;
use Kwidoo\SmsVerification\Contracts\ChallengeDriver;
use Kwidoo\SmsVerification\Contracts\ChallengeVerifierInterface;
use Kwidoo\SmsVerification\Verifiers\TwilioVerifier;
use Twilio\Http\GuzzleClient;
use Twilio\Rest\Client;

/**
 * `twilio`: Twilio Verify v2. Twilio generates and checks the code.
 */
final class TwilioDriver implements ChallengeDriver
{
    use DriverSupport;

    public const NAME = 'twilio';

    public function name(): string
    {
        return self::NAME;
    }

    public function make(array $config, ChallengeRuntime $runtime): ChallengeVerifierInterface
    {
        $client = new Client(
            $this->required($config, 'account_sid'),
            $this->required($config, 'auth_token'),
            null,
            null,
            new GuzzleClient($this->guzzle($runtime)),
        );

        return new TwilioVerifier($client, [
            'verify_sid' => $this->required($config, 'verify_sid'),
            'channel' => $this->string($config, 'channel', 'sms'),
            'locale' => $this->string($config, 'locale'),
            'ttl' => $this->integer($config, 'ttl', TwilioVerifier::DEFAULT_TTL),
        ]);
    }

    public function options(): array
    {
        return [
            self::option('account_sid', 'Twilio account SID', 'Account SID (AC...) from the Twilio console.', required: true),
            self::option('auth_token', 'Twilio auth token', 'Auth token (or API key secret) for the account.', required: true, sensitive: true),
            self::option('verify_sid', 'Twilio Verify service SID', 'Verify service SID (VA...).', required: true),
            self::option('channel', 'Channel', 'Verify channel: sms, call, whatsapp, email.', default: 'sms'),
            self::option('locale', 'Locale', 'Language of the Verify message, e.g. en, de.'),
            $this->ttlOption(TwilioVerifier::DEFAULT_TTL),
        ];
    }

    public function redaction(): array
    {
        // The code travels form-encoded to /VerificationCheck.
        return ['patterns' => ['/(?:^|[?&])Code=([^&\s"]+)/']];
    }
}
