<?php

namespace Kwidoo\SmsVerification\Challenge\Drivers;

use Kwidoo\SmsVerification\Challenge\ChallengeRuntime;
use Kwidoo\SmsVerification\Contracts\ChallengeDriver;
use Kwidoo\SmsVerification\Contracts\ChallengeVerifierInterface;
use Kwidoo\SmsVerification\Verifiers\TelesignVerifyVerifier;
use telesign\enterprise\sdk\verify\OmniVerifyClient;

/**
 * `telesign_verify`: Telesign Verify API, verify.telesign.com (full-service).
 * Telesign generates and checks the code.
 */
final class TelesignVerifyDriver implements ChallengeDriver
{
    use TelesignDriverSupport;

    public const NAME = 'telesign_verify';

    public const DEFAULT_URL = 'https://verify.telesign.com';

    public function name(): string
    {
        return self::NAME;
    }

    public function make(array $config, ChallengeRuntime $runtime): ChallengeVerifierInterface
    {
        [$customerId, $apiKey] = $this->credentials($config);

        return new TelesignVerifyVerifier(
            // telesign/telesignenterprise 5.x: (customer_id, api_key, rest_endpoint,
            // timeout, proxy, handler); the SDK adds its version arguments.
            new OmniVerifyClient($customerId, $apiKey, $this->url($config), $runtime->timeout, null, $this->handler($runtime)),
            [
                'methods' => $this->string($config, 'methods', 'sms'),
                'message_template' => $this->string($config, 'message_template'),
                'ttl' => $this->integer($config, 'ttl', TelesignVerifyVerifier::DEFAULT_TTL),
            ],
        );
    }

    public function options(): array
    {
        return [
            ...$this->credentialOptions(),
            $this->urlOption('Verify API'),
            self::option('methods', 'Verification methods', 'Ordered, comma separated verification policy, e.g. sms or whatsapp,sms.', default: 'sms'),
            self::option('message_template', 'Message template', 'Name of a message template configured with Telesign.'),
            $this->ttlOption(TelesignVerifyVerifier::DEFAULT_TTL),
        ];
    }

    public function redaction(): array
    {
        // The code on its way to PATCH /verification/{id}/state.
        return ['fields' => ['security_factor']];
    }
}
