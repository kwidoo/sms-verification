<?php

namespace Kwidoo\SmsVerification;

use Illuminate\Support\ServiceProvider;
use Twilio\Rest\Client as TwilioClient;
use Vonage\Client as VonageClient;
use Vonage\Client\Credentials\Basic;
use Vonage\Client\Credentials\Container as CredentialsContainer;

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

        $this->app->singleton(VerifierFactory::class, function ($app) {
            return new VerifierFactory($app);
        });
    }
}
