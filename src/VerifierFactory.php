<?php

namespace Kwidoo\SmsVerification;

use Illuminate\Contracts\Container\Container;
use Kwidoo\SmsVerification\Contracts\VerifierInterface;
use Kwidoo\SmsVerification\Exceptions\VerifierException;
use Kwidoo\SmsVerification\Verifiers\PlivoVerifier;
use Kwidoo\SmsVerification\Verifiers\RoundRobinVerifier;
use Kwidoo\SmsVerification\Verifiers\TwilioVerifier;
use Kwidoo\SmsVerification\Verifiers\VonageVerifier;

use Twilio\Rest\Client as TwilioClient;
use Vonage\Client as VonageClient;
use Plivo\RestClient as PlivoClient;

class VerifierFactory
{
    protected $app;

    public function __construct(Container $app)
    {
        $this->app = $app;
    }

    /**
     * Create an SMS verifier based on configuration or explicit provider.
     *
     * @param string|null $provider
     * @return mixed
     * @throws \InvalidArgumentException
     */
    public function make($provider = null)
    {
        if (!$provider) {
            $provider = config('sms-verification.default');
        }

        if (method_exists($this, 'make' . ucfirst($provider))) {
            return $this->{'make' . ucfirst($provider)}();
        }

        throw new VerifierException("Unsupported SMS provider [$provider].");
    }

    /**
     * @param string[] $providers
     *
     * @return VerifierInterface
     */
    protected function makeRoundRobin(array $providers = []): VerifierInterface
    {
        $instances = [];

        if (empty($providers)) {
            $providers = config('sms-verification.round_robin.providers');
        }

        if (empty($providers)) {
            throw new VerifierException("No round-robin providers configured.");
        }

        foreach ($providers as $provider) {
            $method = 'make' . ucfirst($provider);
            if (!method_exists($this, $method)) {
                throw new VerifierException("Unsupported SMS provider [{$provider}] in round-robin.");
            }
            $instances[] = $this->{$method}();
        }
        return new RoundRobinVerifier($instances);
    }

    /**
     * Create an instance of TwilioVerifier.
     *
     * @return VerifierInterface
     */
    protected function makeTwilio(): VerifierInterface
    {
        return new TwilioVerifier($this->app->make(TwilioClient::class));
    }

    /**
     * Create an instance of VonageVerifier.
     *
     * @return VerifierInterface
     */
    protected function makeVonage(): VerifierInterface
    {
        return new VonageVerifier($this->app->make(VonageClient::class));
    }

    /**
     * Create an instance of VonageVerifier.
     *
     * @return VerifierInterface
     */
    protected function makePlivo(): VerifierInterface
    {
        return new PlivoVerifier($this->app->make(PlivoClient::class));
    }
}
