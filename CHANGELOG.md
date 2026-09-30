# Changelog

All notable changes to `sms-verifications` will be documented in this file

## 1.2.0 - unreleased

- Telesign is back, on `telesign/telesign` ^5.2 (the SDK now allows `psr/http-message` 2.x).
- Telesign endpoint is configurable (`TELESIGN_REST_ENDPOINT`) and defaults to production:
  SDK 5.x otherwise targets `rest-api-test.telesign.com`. SDK 5.x also moved `timeout` from the
  4th to the 7th constructor argument.
- New stateless challenge API: `ChallengeVerifierInterface::dispatch(): Challenge` and
  `verify(Challenge, code): bool`. The code is never stored; the challenge carries a keyed MAC
  (`SMS_VERIFICATION_CODE_KEY`, falls back to `APP_KEY`) bound to recipient, reference and expiry.
  Implemented by `TelesignVerifier`.
- Fix: Telesign `validate()` compared a cached int with the submitted string, so it never succeeded.
- Codes come from `random_int()` (was `rand()`), 6 digits by default (`TELESIGN_CODE_LENGTH`),
  message template and TTL configurable (`TELESIGN_MESSAGE` with `:code`, `TELESIGN_CODE_TTL`).
- New `TelesignVerifyVerifier` (Verify API, `verify.telesign.com`) and `TelesignSmsVerifyVerifier`
  (SMS Verify API, `/v1/verify/sms`) for full-service accounts: Telesign generates and checks the code, the
  challenge carries only `reference_id`. Factory names `telesignVerify`, `telesignSmsVerify`; requires
  `telesign/telesignenterprise` ^5.3.
- Challenge drivers: `ChallengeDriver` + `ChallengeDrivers` registry build a `ChallengeVerifierInterface` per call from a
  plain config array and a `ChallengeRuntime` (host Guzzle handler, code key, timeout), and declare their options and
  log redaction. For multi-tenant hosts that cannot use the global `.env` config. Drivers: `telesign`, `telesign_verify`,
  `telesign_sms_verify`. Configuration problems raise `ConfigurationException` (a `VerifierException`).
- Drivers and the stateless challenge API for every provider: `twilio`, `vonage`, `telnyx`, `plivo`, `sinch`, `seven`
  (next to the three Telesign ones). All verifiers implement `ChallengeVerifierInterface`; constructors stay compatible
  (new optional arguments only) and the 1.x `create()`/`validate()` behave as before, except for the fixes below.
- Fix: Telnyx `validate()` never submitted the code and compared `!$status === 'accepted'` (always false), so every code
  passed. It now checks the code (`POST /verifications/{id}/actions/verify`). Telnyx calls go through the new
  `Clients\TelnyxVerifyClient` instead of the SDK's static client.
- Fix: seven.io generated codes with `rand()` and compared them as ints (`0123` == `123`); now `random_int()` and a
  constant-time string comparison.
- Fix: Plivo read `session_uuid` / `message` from response objects as arrays, which never worked.
- `SinchClient` takes an optional Guzzle handler and timeout, and can report a code by verification id.
- New `Clients\SevenHttpClient` sends seven.io SDK calls over Guzzle.
- Telesign numbers are sent as digits only (no `+`), as Telesign requires.
- Telesign send failures raise `VerifierException` with Telesign's status and errors (the
  response object used to be treated as always successful).

## 1.0.0 - 201X-XX-XX

- initial release
