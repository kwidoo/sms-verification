<?php

namespace Kwidoo\SmsVerification\Verifiers;

use Kwidoo\SmsVerification\Challenge\Challenge;
use Kwidoo\SmsVerification\Clients\SinchClient;
use Kwidoo\SmsVerification\Contracts\ChallengeVerifierInterface;
use Kwidoo\SmsVerification\Contracts\VerifierInterface;
use Kwidoo\SmsVerification\Exceptions\VerifierException;
use Kwidoo\SmsVerification\Verifiers\Concerns\ProviderOwnedChallenges;

/**
 * Sinch Verification: Sinch generates, delivers and checks the code.
 *
 * Challenge reference = the verification id; the code is reported by id,
 * `status` SUCCESSFUL means verified.
 */
class SinchVerifier extends Verifier implements VerifierInterface, ChallengeVerifierInterface
{
    use ProviderOwnedChallenges;

    public const DEFAULT_TTL = 600;

    /** Report outcomes that are a verdict: the verification is unknown, expired or closed. */
    private const REJECTED_HTTP = [400, 404, 410, 422];

    /**
     * @param  array{ttl?: int}  $options
     */
    public function __construct(protected SinchClient $client, protected array $options = []) {}

    public function create(string $phoneNumber): void
    {
        $number = $this->sanitizePhoneNumber($phoneNumber);
        $response = $this->client->sendVerification($number);

        if (!isset($response['id'])) {
            throw new VerifierException('Failed to get a valid Sinch request ID.');
        }

        if (!isset($response['_links'][1]['href'])) {
            throw new VerifierException('Failed to get a valid Sinch verification URL.');
        }

        cache()->put("sinch$number", $response['_links'][1]['href'], now()->addMinutes(5));
    }

    public function validate(array $credentials): bool
    {
        if (count($credentials) < 2) {
            throw new VerifierException('Credentials array must include [phoneNumber, code].');
        }

        [$phoneNumber, $verificationCode] = $credentials;
        $number = $this->sanitizePhoneNumber($phoneNumber);

        $url = cache()->pull("sinch$number");
        if (!$url) {
            throw new VerifierException('No Sinch verification request found for this number.');
        }

        $response = $this->client->check($url, $verificationCode);

        if (!$response || $response['status'] !== 'SUCCESSFUL') {
            throw new VerifierException('Failed to verify the code.');
        }

        return true;
    }

    protected function startVerification(string $number): string
    {
        try {
            $response = $this->client->sendVerification($number);
        } catch (\Throwable $e) {
            throw new VerifierException('Sinch request failed: '.$e->getMessage(), 0, $e);
        }

        $id = $response->json('id');

        if (!$response->successful() || !is_string($id) || $id === '') {
            throw new VerifierException(sprintf('Sinch refused the verification: HTTP %d %s', $response->status(), (string) $response->json('message')));
        }

        return $id;
    }

    protected function checkCode(Challenge $challenge, string $code): bool
    {
        try {
            $response = $this->client->reportById((string) $challenge->reference, $code);
        } catch (\Throwable $e) {
            throw new VerifierException('Sinch request failed: '.$e->getMessage(), 0, $e);
        }

        if ($response->successful()) {
            return $response->json('status') === 'SUCCESSFUL';
        }

        if (in_array($response->status(), self::REJECTED_HTTP, true)) {
            return false;
        }

        throw new VerifierException(sprintf('Sinch could not check the code: HTTP %d %s', $response->status(), (string) $response->json('message')));
    }
}
