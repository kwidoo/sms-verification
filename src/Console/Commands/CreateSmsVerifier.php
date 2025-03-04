<?php

namespace Kwidoo\SmsVerification\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

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

        // If the directory does not exist, create it using the File facade.
        if (!File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true);
            $this->info("Created directory: {$directory}");
        }

        // Determine the file path for the new verifier.
        $filePath = $directory . '/' . $name . '.php';

        // Use the File facade to check if the file exists.
        if (File::exists($filePath)) {
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
use {client} as Client;

class {name} extends Verifier implements VerifierInterface
{
    public function __construct(protected Client $client) {}

    /**
     * @inheritdoc
     */
    public function create(string $phoneNumber): void
    {
        // Implement creation logic
    }

    /**
     * @inheritdoc
     */
    public function validate(array $credentials): bool
    {
        // Implement validation logic
        return true;
    }
}
STUB;

        // Replace placeholders in the stub.
        $stub = str_replace('{name}', $name, $stub);
        $stub = str_replace('{client}', $clientClass, $stub);

        // Use the File facade to write the new verifier file.
        File::put($filePath, $stub);
        $this->info("Verifier {$name} created successfully at {$filePath}");
    }
}
