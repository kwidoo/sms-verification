<?php

namespace Kwidoo\SmsVerification;

use Illuminate\Support\ServiceProvider;
use Kwidoo\SmsVerification\Clients\SinchClient;
use Twilio\Rest\Client as TwilioClient;
use Vonage\Client as VonageClient;
use Plivo\RestClient as PlivoClient;
use Seven\Api\Client as SevenClient;
use Vonage\Client\Credentials\Basic;
use Vonage\Client\Credentials\Container as CredentialsContainer;
use telesign\sdk\messaging\MessagingClient as TelesignClient;
use telesign\enterprise\sdk\verify\OmniVerifyClient as TelesignOmniVerifyClient;
use telesign\enterprise\sdk\verify\VerifyClient as TelesignVerifyClient;
use Kwidoo\SmsVerification\Console\Commands\CreateSmsVerifier;


class SmsVerificationProvider extends ServiceProvider
{
    public function boot()
    {
        //   $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->publishes([
            __DIR__ . '/../config/sms-verification.php' => config_path('sms-verification.php'),
        ]);

        // $this->loadRoutesFrom(__DIR__ . '/Http/routes.php');
    }

    public function register()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                CreateSmsVerifier::class,
            ]);
        }

        $this->mergeConfigFrom(__DIR__ . '/../config/sms-verification.php', 'sms-verification');

        $this->app->singleton(TwilioClient::class, function () {
            return new TwilioClient(
                config('sms-verification.twilio.sid'),
                config('sms-verification.twilio.auth_token')
            );
        });

        $this->app->singleton(VonageClient::class, function () {
            $basic  = new Basic(
                config('sms-verification.vonage.api_key'),
                config('sms-verification.vonage.api_secret')
            );
            $client = new VonageClient(new CredentialsContainer($basic));

            return $client;
        });

        $this->app->singleton(PlivoClient::class, function () {
            return new PlivoClient(
                config('sms-verification.plivo.auth_id'),
                config('sms-verification.plivo.auth_token')
            );
        });

        $this->app->singleton(SinchClient::class, function () {
            return new SinchClient(
                config('sms-verification.sinch.api_key'),
                config('sms-verification.sinch.api_secret'),
                config('sms-verification.sinch.verification_url')
            );
        });

        $this->app->singleton(TelesignClient::class, function () {
            // telesign/telesign 5.x: (customer_id, api_key, rest_endpoint,
            // source, sdk_version_origin, sdk_version_dependency, timeout).
            return new TelesignClient(
                config('sms-verification.telesign.customer_id'),
                config('sms-verification.telesign.api_key'),
                config('sms-verification.telesign.rest_endpoint', 'https://rest-api.telesign.com'),
                'php_telesign',
                null,
                null,
                config('sms-verification.telesign.timeout', 10)
            );
        });

        // telesign/telesignenterprise 5.x: (customer_id, api_key, rest_endpoint,
        // timeout, proxy, handler) - the SDK fills in the version arguments.
        $this->app->singleton(TelesignOmniVerifyClient::class, function () {
            return new TelesignOmniVerifyClient(
                config('sms-verification.telesign.customer_id'),
                config('sms-verification.telesign.api_key'),
                config('sms-verification.telesign_verify.rest_endpoint', 'https://verify.telesign.com'),
                config('sms-verification.telesign_verify.timeout', 10)
            );
        });

        $this->app->singleton(TelesignVerifyClient::class, function () {
            return new TelesignVerifyClient(
                config('sms-verification.telesign.customer_id'),
                config('sms-verification.telesign.api_key'),
                config('sms-verification.telesign_sms_verify.rest_endpoint', 'https://rest-ww.telesign.com'),
                config('sms-verification.telesign_sms_verify.timeout', 10)
            );
        });

        $this->app->singleton(SevenClient::class, function () {
            return new SevenClient(
                config('sms-verification.sevenio.api_key'),
            );
        });

        $this->app->singleton(VerifierFactory::class, function ($app) {
            return new VerifierFactory($app);
        });
    }
}
