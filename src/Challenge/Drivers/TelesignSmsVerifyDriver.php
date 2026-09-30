<?php

namespace Kwidoo\SmsVerification\Challenge\Drivers;

use Kwidoo\SmsVerification\Challenge\ChallengeRuntime;
use Kwidoo\SmsVerification\Contracts\ChallengeDriver;
use Kwidoo\SmsVerification\Contracts\ChallengeVerifierInterface;
use Kwidoo\SmsVerification\Verifiers\TelesignSmsVerifyVerifier;
use telesign\enterprise\sdk\verify\VerifyClient;

/**
 * `telesign_sms_verify`: Telesign SMS Verify API, /v1/verify/sms (full-service).
 * Telesign generates and checks the code.
 */
final class TelesignSmsVerifyDriver implements ChallengeDriver
{
    use TelesignDriverSupport;

    public const NAME = 'telesign_sms_verify';

    public const DEFAULT_URL = 'https://rest-ww.telesign.com';

    public function name(): string
    {
        return self::NAME;
    }

    public function make(array $config, ChallengeRuntime $runtime): ChallengeVerifierInterface
    {
        [$customerId, $apiKey] = $this->credentials($config);

        return new TelesignSmsVerifyVerifier(
            new VerifyClient($customerId, $apiKey, $this->url($config), $runtime->timeout, null, $this->handler($runtime)),
            [
                'template' => $this->string($config, 'message'),
                'language' => $this->string($config, 'language'),
                'ttl' => $this->integer($config, 'ttl', TelesignSmsVerifyVerifier::DEFAULT_TTL),
            ],
        );
    }

    public function options(): array
    {
        return [
            ...$this->credentialOptions(),
            $this->urlOption('SMS Verify API'),
            ['key' => 'message', 'label' => 'SMS text', 'type' => 'string', 'required' => false, 'sensitive' => false,
                'description' => 'Message text; :code (or $$CODE$$) is replaced by Telesign. Empty uses Telesign\'s text.'],
            ['key' => 'language', 'label' => 'Template language', 'type' => 'string', 'required' => false, 'sensitive' => false,
                'description' => 'Language of Telesign\'s predefined text, used when no SMS text is set.'],
            $this->ttlOption(TelesignSmsVerifyVerifier::DEFAULT_TTL),
        ];
    }

    public function redaction(): array
    {
        // The code travels in the query string of GET /v1/verify/{id}.
        return ['fields' => ['verify_code']];
    }
}
