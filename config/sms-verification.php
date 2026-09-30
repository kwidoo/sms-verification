<?php

return [
    'verifiers' => [
        'twilio' => \Kwidoo\SmsVerification\Verifiers\TwilioVerifier::class,
        'vonage' => \Kwidoo\SmsVerification\Verifiers\VonageVerifier::class,
        'telnyx' => \Kwidoo\SmsVerification\Verifiers\TelnyxVerifier::class,
        'plivo' => \Kwidoo\SmsVerification\Verifiers\PlivoVerifier::class,
        'sinch' => \Kwidoo\SmsVerification\Verifiers\SinchVerifier::class,
        'telesign' => \Kwidoo\SmsVerification\Verifiers\TelesignVerifier::class,
        'telesignVerify' => \Kwidoo\SmsVerification\Verifiers\TelesignVerifyVerifier::class,
        'telesignSmsVerify' => \Kwidoo\SmsVerification\Verifiers\TelesignSmsVerifyVerifier::class,
        'seven' => \Kwidoo\SmsVerification\Verifiers\SevenVerifier::class,
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
    'telesign' => [
        'customer_id' => config('telesign.customer_id', env('TELESIGN_CUSTOMER_ID')),
        'api_key' => config('telesign.api_key', env('TELESIGN_API_KEY')),
        // SDK 5.x defaults to the sandbox (rest-api-test.telesign.com), so the
        // endpoint is always passed explicitly.
        'rest_endpoint' => config('telesign.rest_endpoint', env('TELESIGN_REST_ENDPOINT', 'https://rest-api.telesign.com')),
        'timeout' => (float) config('telesign.timeout', env('TELESIGN_TIMEOUT', 10)),
        'message' => config('telesign.message', env('TELESIGN_MESSAGE', 'Your verification code is :code')),
        'message_type' => 'OTP',
        'code_length' => (int) env('TELESIGN_CODE_LENGTH', 6),
        'ttl' => (int) env('TELESIGN_CODE_TTL', 300),
    ],
    // Telesign Verify API (full-service): Telesign generates and checks the code.
    // Uses the telesign customer_id / api_key above.
    'telesign_verify' => [
        'rest_endpoint' => env('TELESIGN_VERIFY_ENDPOINT', 'https://verify.telesign.com'),
        'timeout' => (float) env('TELESIGN_TIMEOUT', 10),
        'methods' => env('TELESIGN_VERIFY_METHODS', 'sms'),
        'message_template' => env('TELESIGN_VERIFY_MESSAGE_TEMPLATE'),
        'ttl' => (int) env('TELESIGN_CODE_TTL', 300),
    ],
    // Telesign SMS Verify API (/v1/verify/sms, full-service): Telesign
    // generates and checks the code.
    'telesign_sms_verify' => [
        'rest_endpoint' => env('TELESIGN_SMS_VERIFY_ENDPOINT', 'https://rest-ww.telesign.com'),
        'timeout' => (float) env('TELESIGN_TIMEOUT', 10),
        'template' => env('TELESIGN_SMS_VERIFY_TEMPLATE'),
        'language' => env('TELESIGN_SMS_VERIFY_LANGUAGE'),
        'ttl' => (int) env('TELESIGN_CODE_TTL', 300),
    ],
    // Key for the stateless challenge API (dispatch()/verify()). Falls back to
    // the application key. Rotating it invalidates challenges in flight.
    'challenge' => [
        'key' => env('SMS_VERIFICATION_CODE_KEY'),
    ],
    'sevenio' => [
        'api_key' => config('sevenio.api_key', env('SEVENIO_API_KEY')),
    ],
];
