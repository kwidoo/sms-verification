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
- Telesign numbers are sent as digits only (no `+`), as Telesign requires.
- Telesign send failures raise `VerifierException` with Telesign's status and errors (the
  response object used to be treated as always successful).

## 1.0.0 - 201X-XX-XX

- initial release
