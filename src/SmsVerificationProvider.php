<?php

namespace Kwidoo\SmsVerification;

use Illuminate\Support\ServiceProvider;
use Kwidoo\SmsVerification\Clients\SinchClient;
use Twilio\Rest\Client as TwilioClient;
use Vonage\Client as VonageClient;
use Plivo\RestClient as PlivoClient;
use Vonage\Client\Credentials\Basic;
use Vonage\Client\Credentials\Container as CredentialsContainer;
use telesign\sdk\messaging\MessagingClient as TelesignClient;
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
            return new TelesignClient(
                config('sms-verification.telesign.customer_id'),
                config('sms-verification.telesign.api_key')
            );
        });

        $this->app->singleton(VerifierFactory::class, function ($app) {
            return new VerifierFactory($app);
        });
    }
}
