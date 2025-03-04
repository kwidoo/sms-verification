// File: src/VerifierFactory.php
<?php

namespace Kwidoo\SmsVerification;

use Illuminate\Contracts\Container\Container;
use Kwidoo\SmsVerification\Contracts\VerifierInterface;
use Kwidoo\SmsVerification\Exceptions\VerifierException;
use Kwidoo\SmsVerification\Verifiers\RoundRobinVerifier;
use Kwidoo\SmsVerification\Verifiers\TwilioVerifier;
use Kwidoo\SmsVerification\Verifiers\VonageVerifier;

use Twilio\Rest\Client as TwilioClient;
use Vonage\Client as VonageClient;


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
            $providers = config('sms-verification.round_robin');
        }

        foreach (config('sms-verification.round_robin') as $provider) {
            if (!method_exists($this, 'make' . ucfirst($provider))) {
                throw new VerifierException("Unsupported SMS provider [$provider].");
            }
            $instances[] = $this->{'make' . ucfirst($provider)}();
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
}


// File: src/Contracts/VerifierInterface.php
<?php

namespace Kwidoo\SmsVerification\Contracts;

interface VerifierInterface
{
    public function create(string $username): void;
    public function validate(array $credentials): bool;
}


// File: src/Verifiers/Verifier.php
<?php

namespace Kwidoo\SmsVerification\Verifiers;


use Kwidoo\SmsVerification\Contracts\VerifierInterface;

abstract class Verifier implements VerifierInterface
{
    abstract public function create(string $username): void;
    abstract public function validate(array $credentials): bool;

    /**
     * Sanitize the phone number by ensuring only numbers and '+' are present.
     * If the phone number doesn't start with '+', add it.
     *
     * @param string $phoneNumber
     *
     * @return string
     */
    public function sanitizePhoneNumber(string $phoneNumber): string
    {
        $phoneNumber = preg_replace('/[^0-9+]/', '', $phoneNumber);

        if (strpos($phoneNumber, '+') !== 0) {
            $phoneNumber = '+' . $phoneNumber;
        }

        return $phoneNumber;
    }
}


// File: src/Verifiers/VonageVerifier.php
<?php

namespace Kwidoo\SmsVerification\Verifiers;

use Kwidoo\SmsVerification\Contracts\VerifierInterface;
use Kwidoo\SmsVerification\Exceptions\VerifierException;
use Kwidoo\SmsVerification\Verifiers\Verifier;
use Vonage\Client;
use Vonage\Verify2\Request\SMSRequest;

class VonageVerifier extends Verifier implements VerifierInterface
{
    public function __construct(protected Client $client) {}

    /**
     * @param string $phoneNumber Phone number for Vonage verification
     *
     * @return void
     */
    public function create(string $phoneNumber): void
    {
        $number = $this->sanitizePhoneNumber($phoneNumber);
        $newRequest = new SMSRequest($number, config('sms-verification.vonage.brand'));
        $response = $this->client->verify2()->startVerification($newRequest);

        cache()->put("vonage$number", $response['request_id'], now()->addMinutes(5));
    }

    /**
     * @param array $credentials [phone number, verification code]
     *
     * @return bool
     */
    public function validate(array $credentials): bool
    {
        $number = $this->sanitizePhoneNumber($credentials[0]);

        $requestId = cache()->pull("vonage$number");

        $response = $this->client->verify2()->check($requestId, $credentials[1]);

        if (!$response) {
            throw new VerifierException('Invalid verification code');
        }

        return true;
    }
}


// File: src/Verifiers/TwilioVerifier.php
<?php

namespace Kwidoo\SmsVerification\Verifiers;

use Kwidoo\SmsVerification\Contracts\VerifierInterface;
use Kwidoo\SmsVerification\Exceptions\VerifierException;
use Twilio\Rest\Client;

class TwilioVerifier extends Verifier implements VerifierInterface
{
    public function __construct(protected Client $client) {}

    /**
     * @param string $username Phone number for Twilio verification
     *
     * @return void
     */
    public function create(string $phoneNumber): void
    {
        $number = $this->sanitizePhoneNumber($phoneNumber);

        $this->client->verify->v2->services(config('twilio.verify_sid'))
            ->verifications
            ->create($number, 'sms');
    }


    /**
     * @param array $credentials [phone number, verification code]
     *
     * @return bool
     */
    public function validate(array $credentials): bool
    {
        $number = $this->sanitizePhoneNumber($credentials[0]);

        $verification = $this->client
            ->verify
            ->v2
            ->services(config('twilio.verify_sid'))
            ->verificationChecks
            ->create([
                'to' => $number,
                'code' => $credentials[1],
            ]);

        if (!$verification->valid) {
            throw new VerifierException('Invalid verification code');
        }

        return true;
    }
}


// File: src/Verifiers/RoundRobinVerifier.php
<?php

namespace Kwidoo\SmsVerification\Verifiers;

use Kwidoo\SmsVerification\Contracts\VerifierInterface;

class RoundRobinVerifier extends Verifier implements VerifierInterface
{
    public function __construct(protected array $verifiers) {}

    protected function getNextVerifier(): VerifierInterface
    {
        $currentIndex = cache()->get(config('sms-verification.round_robin_cache_key', 'round_robin_verifiers'), 0);

        $verifier = $this->verifiers[$currentIndex];
        $newIndex = ($currentIndex + 1) % count($this->verifiers);

        cache()->put('round_robin_verifiers', $newIndex, now()->addMinutes(10));

        return $verifier;
    }

    /**
     * @param string $username Phone number for Twilio verification
     *
     * @return void
     */
    public function create(string $phoneNumber): void
    {
        $this->getNextVerifier()->create($phoneNumber);
    }


    /**
     * @param array $credentials [phone number, verification code]
     *
     * @return bool
     */
    public function validate(array $credentials): bool
    {
        return $this->getNextVerifier()->validate($credentials);
    }
}


// File: src/Exceptions/VerifierException.php
<?php

namespace Kwidoo\SmsVerification\Exceptions;

use Exception;

class VerifierException extends Exception
{
    //
}


// File: src/SmsVerificationProvider.php
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


// File: src/Console/Commands/CreateSmsVerifier.php
<?php

namespace Kwidoo\SmsVerification\Console\Commands;

use Illuminate\Console\Command;

class CreateSmsVerifier extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'verifier:create-sms-verifier {name?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate a new SMS verifier class from a stub';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Get the verifier name from argument or ask the user.
        $name = $this->argument('name') ?: $this->ask('Enter the name of the SMS verifier class');

        if (empty($name)) {
            $this->error("Verifier name cannot be empty!");
            return;
        }

        // Determine the directory for verifiers.
        $directory = app_path('Verifiers');

        // If the directory does not exist, create it.
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
            $this->info("Created directory: {$directory}");
        }

        // Determine the file path for the new verifier.
        $filePath = $directory . '/' . $name . '.php';

        if (file_exists($filePath)) {
            $this->error("A verifier with the name {$name} already exists.");
            return;
        }

        // Ask for the client class to use; defaulting to App\Client.
        $clientClass = $this->ask('Enter the client class (including namespace) for this verifier', 'App\Client');

        // Define the stub content.
        $stub = <<<'STUB'
<?php

namespace App\Verifiers;

use Kwidoo\SmsVerification\Contracts\VerifierInterface;
use Kwidoo\SmsVerification\Exceptions\VerifierException;
use Kwidoo\SmsVerification\Verifiers\Verifier;
use {client};

class {name} extends Verifier implements VerifierInterface
{
    public function __construct(protected Client $client) {}

    /**
     * @param string $phoneNumber Phone number for Twilio verification
     *
     * @return void
     */
    public function create(string $phoneNumber): void
    {
       //
    }

    /**
     * @param array $credentials [phone number, verification code]
     *
     * @return bool
     */
    public function validate(array $credentials): bool
    {
        //
    }
}
STUB;

        // Replace placeholders in the stub.
        $stub = str_replace('{name}', $name, $stub);
        $stub = str_replace('{client}', $clientClass, $stub);

        // Write the new verifier file.
        file_put_contents($filePath, $stub);
        $this->info("Verifier {$name} created successfully at {$filePath}");
    }
}


