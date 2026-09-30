<?php

namespace Kwidoo\SmsVerification\Challenge\Drivers;

use Kwidoo\SmsVerification\Challenge\ChallengeRuntime;
use Kwidoo\SmsVerification\Challenge\OtpGenerator;
use Kwidoo\SmsVerification\Contracts\ChallengeDriver;
use Kwidoo\SmsVerification\Contracts\ChallengeVerifierInterface;
use Kwidoo\SmsVerification\Verifiers\TelesignVerifier;
use telesign\sdk\messaging\MessagingClient;

/**
 * `telesign`: Telesign Messaging API (any account). The package generates the
 * code and seals it into the challenge, so it needs ChallengeRuntime::$codeKey.
 */
final class TelesignDriver implements ChallengeDriver
{
    use TelesignDriverSupport;

    public const NAME = 'telesign';

    public const DEFAULT_URL = 'https://rest-api.telesign.com';

    public function name(): string
    {
        return self::NAME;
    }

    public function make(array $config, ChallengeRuntime $runtime): ChallengeVerifierInterface
    {
        [$customerId, $apiKey] = $this->credentials($config);

        $hasher = $this->hasher($runtime);

        return new TelesignVerifier(
            // telesign/telesign 5.x: (customer_id, api_key, rest_endpoint, source,
            // sdk_version_origin, sdk_version_dependency, timeout, proxy, handler).
            new MessagingClient($customerId, $apiKey, $this->url($config), 'php_telesign', null, null, $runtime->timeout, null, $this->handler($runtime)),
            $hasher,
            new OtpGenerator(),
            [
                'message' => $this->string($config, 'message', TelesignVerifier::DEFAULT_MESSAGE),
                'message_type' => TelesignVerifier::DEFAULT_MESSAGE_TYPE,
                'code_length' => $this->integer($config, 'code_length', TelesignVerifier::DEFAULT_CODE_LENGTH),
                'ttl' => $this->integer($config, 'ttl', TelesignVerifier::DEFAULT_TTL),
            ],
        );
    }

    public function options(): array
    {
        return [
            ...$this->credentialOptions(),
            $this->urlOption('Messaging API (sandbox: https://rest-api-test.telesign.com)'),
            self::option('message', 'SMS text', 'Message text; :code is replaced with the one-time code.', default: TelesignVerifier::DEFAULT_MESSAGE),
            self::option('code_length', 'Code length', 'Number of digits, 4 to 10.', 'integer', default: TelesignVerifier::DEFAULT_CODE_LENGTH),
            $this->ttlOption(TelesignVerifier::DEFAULT_TTL),
        ];
    }

    public function redaction(): array
    {
        // The SMS text carries the code; it travels as a form-encoded body.
        return ['patterns' => ['/(?:^|[?&])message=([^&\s"]+)/'], 'words' => ['mac']];
    }
}
