<?php

return [
    'verifiers' => [
        'twilio' => \Kwidoo\SmsVerification\Verifiers\TwilioVerifier::class,
        'vonage' => \Kwidoo\SmsVerification\Verifiers\VonageVerifier::class,
        'telnyx' => \Kwidoo\SmsVerification\Verifiers\TelnyxVerifier::class,
        'plivo' => \Kwidoo\SmsVerification\Verifiers\PlivoVerifier::class,
    ],
    'default' => 'twilio',
    'round_robin' => [
        'verifiers' => ['twilio', 'vonage'],
        'current_verifier_cache_key' => 'round_robin_verifiers_',
        'verifier_for_number_cache_key' => 'round_robin_verifier_for_',
    ],
    'vonage' => [
        'api_key' => config('vonage.api_key', env('VONAGE_API_KEY')),
        'api_secret' => config('vonage.api_secret', env('VONAGE_API_SECRET')),
        'brand' => config('vonage.brand', env('VONAGE_BRAND', 'Kwidoo')),
    ],
    'twilio' => [
        'sid' => config('twilio.sid', env('TWILIO_SID')),
        'auth_token' => config('twilio.auth_token', env('TWILIO_AUTH_TOKEN')),
        'verify_sid' => config('twilio.verify_sid', env('TWILIO_VERIFY_SID')),
    ],
    'telnyx' => [
        'api_key' => config('telnyx.api_key', env('TELNYX_API_KEY')),
        'public_key' => config('telnyx.public_key', env('TELNYX_PUBLIC_KEY')),
        'verify_sid' => config('telnyx.verify_sid', env('TELNYX_VERIFY_SID')),
    ],
    'plivo' => [
        'auth_id' => config('plivo.auth_id', env('PLIVO_AUTH_ID')),
        'auth_token' => config('plivo.auth_token', env('PLIVO_AUTH_TOKEN')),
        'optional_args' => config('plivo.optional_args', []),
    ],
    'sinch' => [
        'api_key' => config('sinch.api_key', env('SINCH_API_KEY')),
        'api_secret' => config('sinch.api_secret', env('SINCH_API_SECRET')),
        'verification_url' => config('sinch.verification_url', env('SINCH_VERIFICATION_URL', 'https://verification.api.sinch.com')),
    ],
];
