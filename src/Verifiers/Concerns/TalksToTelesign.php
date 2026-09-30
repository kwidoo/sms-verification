<?php

namespace Kwidoo\SmsVerification\Verifiers\Concerns;

use Kwidoo\SmsVerification\Exceptions\VerifierException;
use telesign\sdk\rest\Response;

/**
 * Shared by the Telesign verifiers (Messaging, Verify, SMS Verify).
 */
trait TalksToTelesign
{
    /**
     * Telesign wants the number as digits only, country code first: no `+`.
     */
    protected function telesignNumber(string $phoneNumber): string
    {
        return ltrim($this->sanitizePhoneNumber($phoneNumber), '+');
    }

    /**
     * Runs a Telesign SDK call, turning transport failures into VerifierException.
     *
     * @throws VerifierException
     */
    protected function callTelesign(callable $call): Response
    {
        try {
            $response = $call();
        } catch (\Throwable $e) {
            throw new VerifierException('Telesign request failed: '.$e->getMessage(), 0, $e);
        }

        if (!$response instanceof Response) {
            throw new VerifierException('Telesign request failed: no response');
        }

        return $response;
    }

    /**
     * @throws VerifierException
     */
    protected function referenceFrom(Response $response, string $what): string
    {
        if (!$response->ok) {
            throw new VerifierException(sprintf('Telesign refused the %s: %s', $what, $this->describeTelesignFailure($response)));
        }

        $reference = is_array($response->json) ? ($response->json['reference_id'] ?? null) : null;

        if (!is_string($reference) || $reference === '') {
            throw new VerifierException('Telesign response has no reference_id.');
        }

        return $reference;
    }

    protected function telesignStatusCode(Response $response): ?int
    {
        $code = is_array($response->json) ? ($response->json['status']['code'] ?? null) : null;

        return is_numeric($code) ? (int) $code : null;
    }

    protected function describeTelesignFailure(Response $response): string
    {
        $json = is_array($response->json) ? $response->json : [];
        $parts = [sprintf('HTTP %d', $response->status_code)];

        if (isset($json['status']['code'])) {
            $parts[] = sprintf('status %s %s', $json['status']['code'], $json['status']['description'] ?? '');
        }

        foreach ((array) ($json['errors'] ?? []) as $error) {
            if (is_array($error)) {
                $parts[] = sprintf('error %s %s', $error['code'] ?? '', $error['description'] ?? '');
            }
        }

        return trim(implode('; ', $parts));
    }

    /**
     * A code as typed by a user: trimmed, digits only, sensible length.
     */
    protected function normalizeCode(string $code, int $min = 3, int $max = 10): ?string
    {
        $code = trim($code);

        if (!ctype_digit($code) || strlen($code) < $min || strlen($code) > $max) {
            return null;
        }

        return $code;
    }
}
