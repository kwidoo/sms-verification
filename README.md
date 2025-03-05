[![Latest Version on Packagist](https://img.shields.io/packagist/v/kwidoo/sms-verification.svg?style=flat-square)](https://packagist.org/packages/kwidoo/sms-verification)
[![Total Downloads](https://img.shields.io/packagist/dt/kwidoo/sms-verification.svg?style=flat-square)](https://packagist.org/packages/kwidoo/sms-verification)
![GitHub Actions](https://github.com/kwidoo/sms-verification/actions/workflows/main.yml/badge.svg)

---

# Laravel SMS Verification

> A Laravel package for sending and validating SMS-based verification codes through multiple providers (e.g., **Twilio**, **Vonage**, or any custom provider\*\*).

## Overview

- **Round Robin Support**: Optionally cycle through multiple providers.
- **Pluggable Architecture**: Implement your own custom verifier(s).
- **Abstracted Interface**: A single interface for `create()` (sending code) and `validate()` (checking code).

## Table of Contents

- [Installation](#installation)
- [Configuration](#configuration)
- [Usage](#usage)
  - [Basic Usage](#basic-usage)
  - [Round Robin Usage](#round-robin-usage)
  - [Custom Verifiers](#custom-verifiers)
- [Console Command](#console-command)
- [Example Code](#example-code)
- [Credits](#credits)
- [License](#license)
- [TODO](#todo)

---

## Installation

1. **Require via Composer**:

   ```bash
   composer require kwidoo/sms-verification
   ```

2. **Publish Config (Optional)**:

   This will publish `sms-verification.php` into your Laravel `config` directory.

   ```bash
   php artisan vendor:publish --provider="Kwidoo\SmsVerification\SmsVerificationProvider" --tag="sms-verification-config"
   ```

3. **Configure Environment Variables**:

   In your `.env` file, make sure to set the appropriate credentials for your desired providers. For example:

   ```env
   # Twilio
   TWILIO_SID=xxxxxxxxxx
   TWILIO_AUTH_TOKEN=xxxxxxxxxx
   TWILIO_VERIFY_SID=xxxxxxxxxx

   # Vonage
   VONAGE_API_KEY=xxxxxxxxxx
   VONAGE_API_SECRET=xxxxxxxxxx
   VONAGE_BRAND="My Awesome App"
   ```

---

## Configuration

The default configuration file `sms-verification.php` looks like this:

```php
return [
    'verifiers' => [
        'twilio' => \Kwidoo\SmsVerification\Verifiers\TwilioVerifier::class,
        'vonage' => \Kwidoo\SmsVerification\Verifiers\VonageVerifier::class,
    ],
    'default' => 'twilio',
    'round_robin' => [
        'verifiers' => ['twilio', 'vonage'],
        'current_verifier_cache_key' => 'round_robin_verifiers_',
        'verifier_for_number_cache_key' => 'round_robin_verifier_for_',
    ],

    'vonage' => [
        'api_key' => env('VONAGE_API_KEY'),
        'api_secret' => env('VONAGE_API_SECRET'),
        'brand' => env('VONAGE_BRAND', 'MyApp'),
    ],
    'twilio' => [
        'sid' => env('TWILIO_SID'),
        'auth_token' => env('TWILIO_AUTH_TOKEN'),
        'verify_sid' => env('TWILIO_VERIFY_SID'),
    ],
];
```

- **`default`**: Indicates the default SMS provider if one is not explicitly specified.
- **`round_robin`**: An array of provider keys to use in a rotating fashion when `RoundRobinVerifier` is requested.
- **`round_robin_cache_key`**: Cache key used to persist the index in the round-robin cycle.
- **`twilio`, `vonage`**: Provider-specific credentials and settings.

---

## Usage

### Basic Usage

If you only want to use **one** provider (e.g., Twilio) for all verifications:

1. **Set** `'default' => 'twilio'` in `sms-verification.php`.
2. In your code, you can do something like:

   ```php
   use Kwidoo\SmsVerification\VerifierFactory;

   class SomeController extends Controller
   {
       public function sendCode(Request $request, VerifierFactory $factory)
       {
           $phoneNumber = $request->input('phone_number');
           $verifier = $factory->make(); // uses default (twilio)
           $verifier->create($phoneNumber);

           return response()->json(['status' => 'Verification code sent.']);
       }

       public function checkCode(Request $request, VerifierFactory $factory)
       {
           $phoneNumber = $request->input('phone_number');
           $code = $request->input('code');
           $verifier = $factory->make(); // uses default (twilio)

           if ($verifier->validate([$phoneNumber, $code])) {
               return response()->json(['status' => 'Code is valid!']);
           }

           // If invalid, handle appropriately
           return response()->json(['error' => 'Invalid code'], 422);
       }
   }
   ```

### Round Robin Usage

If you want to **rotate** between providers (e.g., Twilio → Vonage → Twilio → Vonage…), you can request the _round-robin_ verifier:

1. **`round_robin`** is an array of strings referencing your verifiers: `['twilio', 'vonage']`.
2. In your code, you might do:

   ```php
   use Kwidoo\SmsVerification\VerifierFactory;

   class SomeController extends Controller
   {
       public function sendCode(Request $request, VerifierFactory $factory)
       {
           $phoneNumber = $request->input('phone_number');
           $verifier = $factory->make('roundRobin');
           $verifier->create($phoneNumber);

           return response()->json(['status' => 'Verification code sent.']);
       }

       public function checkCode(Request $request, VerifierFactory $factory)
       {
           $phoneNumber = $request->input('phone_number');
           $code = $request->input('code');
           $verifier = $factory->make('roundRobin');

           if ($verifier->validate([$phoneNumber, $code])) {
               return response()->json(['status' => 'Code is valid!']);
           }

           // If invalid, handle appropriately
           return response()->json(['error' => 'Invalid code'], 422);
       }
   }
   ```

The **round-robin** verifier will:

- On `create()`, pick the next provider in a cycle, send the verification code, and **remember** which provider was used for that phone number.
- On `validate()`, retrieve that same provider to check the code.

### Custom Verifiers

You can create your own SMS verifier by:

1. **Creating** a class that implements `VerifierInterface` (or extend the base `Verifier` class).
2. **Register** it in the container or simply reference its FQN in `sms-verification.verifiers`.

Example:

```php
namespace App\Verifiers;

use Kwidoo\SmsVerification\Verifiers\Verifier;

class MyCustomVerifier extends Verifier
{
    public function create(string $phoneNumber): void
    {
        // Implementation to send code
    }

    public function validate(array $credentials): bool
    {
        // Implementation to check code
        return true;
    }
}
```

Then, in `sms-verification.php`:

```php
'verifiers' => [
   'twilio' => \Kwidoo\SmsVerification\Verifiers\TwilioVerifier::class,
   'vonage' => \Kwidoo\SmsVerification\Verifiers\VonageVerifier::class,
   'my_custom' => \App\Verifiers\MyCustomVerifier::class,
],
```

And in code:

```php
$verifier = $factory->make('my_custom');
```

---

## Console Command

This package includes a console command to generate new custom verifiers from a **stub**:

```bash
php artisan verifier:create-sms-verifier {name?}
```

If you omit `{name}`, the command will prompt you to enter the verifier’s class name. It also asks for the client class (namespace) that the verifier should inject.

The newly generated file will be placed in `app/Verifiers/{Name}.php`. You can customize paths or logic within the command class `CreateSmsVerifier`.

---

## Example Code

Here’s a quick example to tie it all together:

```php
// routes/api.php

use Illuminate\Support\Facades\Route;
use Kwidoo\SmsVerification\VerifierFactory;

Route::post('/send-code', function(\Illuminate\Http\Request $request, VerifierFactory $factory) {
    $phoneNumber = $request->input('phone_number');

    // If you want round robin, pass 'roundRobin' or whichever config key you want:
    // $verifier = $factory->make('roundRobin');
    $verifier = $factory->make(); // default is 'twilio'

    $verifier->create($phoneNumber);
    return response()->json(['message' => 'Code sent']);
});

Route::post('/verify-code', function(\Illuminate\Http\Request $request, VerifierFactory $factory) {
    $phoneNumber = $request->input('phone_number');
    $code = $request->input('code');

    $verifier = $factory->make(); // or 'roundRobin'
    $isValid = $verifier->validate([$phoneNumber, $code]);

    return response()->json(['valid' => $isValid]);
});
```

---

## Credits

- **Twilio** for their robust Verify service.
- **Vonage** (formerly Nexmo) for their Verify APIs.
- **Laravel** community for a great framework to extend.

---

## License

This package is open-sourced software licensed under the [MIT license](LICENSE).

---

# TODO

- [ ] Make more round-robin strategies (e.g., Weighted Round Robin, Random, etc.).
- [ ] Implement a “fallback” approach (try one provider; if it fails, try another).
- [x] Add tests.
- [ ] Add webhook support to better handle fails
- [ ] Add support for other SMS providers:
- [x] Plivo
- [x] Sinch
- [ ] [seven.io](https://www.seven.io/)
- [x] Telesign
- [ ] ClickSend
- [ ] Textmagic
- [ ] SlickText
- [ ] Infobip
- [ ] Routee
- [x] Telnyx
