<?php

namespace Kwidoo\SmsVerification\Verifiers;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Kwidoo\SmsVerification\Challenge\OtpGenerator;
use Kwidoo\SmsVerification\Challenge\OtpHasher;
use Kwidoo\SmsVerification\Contracts\ChallengeVerifierInterface;
use Kwidoo\SmsVerification\Contracts\VerifierInterface;
use Kwidoo\SmsVerification\Exceptions\VerifierException;
use Kwidoo\SmsVerification\Verifiers\Concerns\SealsCodes;
use Seven\Api\Client;
use Seven\Api\Resource\Sms\SmsParams;
use Seven\Api\Resource\Sms\SmsResource;

/**
 * seven.io SMS: seven.io only delivers text, so this verifier generates the
 * code (see {@see SealsCodes}; the 1.x API keeps it in the cache).
 */
class SevenVerifier extends Verifier implements VerifierInterface, ChallengeVerifierInterface
{
    use SealsCodes;

    public const DEFAULT_MESSAGE = 'Your verification code is :code';

    public const DEFAULT_CODE_LENGTH = 6;

    public const DEFAULT_TTL = 300;

    /** seven.io `success` code for an accepted dispatch. */
    private const SUCCESS = 100;

    private const CACHE_PREFIX = 'sevenio';

    /**
     * @param  array{message?: string, code_length?: int, ttl?: int, from?: ?string}  $options
     */
    public function __construct(
        protected Client $client,
        protected ?OtpHasher $hasher = null,
        protected OtpGenerator $generator = new OtpGenerator(),
        protected array $options = [],
        protected ?CacheRepository $cache = null,
    ) {}

    public function create(string $phoneNumber): void
    {
        $number = $this->sanitizePhoneNumber($phoneNumber);
        $code = $this->generator->generate($this->codeLength());

        $this->sendCode($number, $code);

        $this->cache()->put(self::CACHE_PREFIX.$number, $code, now()->addSeconds($this->ttl()));
    }

    public function validate(array $credentials): bool
    {
        if (count($credentials) < 2) {
            throw new VerifierException('Credentials array must include [phoneNumber, code].');
        }

        [$phoneNumber, $verificationCode] = $credentials;
        $number = $this->sanitizePhoneNumber($phoneNumber);

        $code = $this->cache()->pull(self::CACHE_PREFIX.$number);

        if (!$code) {
            throw new VerifierException('No Seven.io verification request found for this number.');
        }

        // Strings, compared in constant time: 1.x cast both to int, so "0123" matched "123".
        if (!hash_equals((string) $code, trim((string) $verificationCode))) {
            throw new VerifierException('Invalid verification code');
        }

        return true;
    }

    protected function sendCode(string $number, string $code): string
    {
        $params = new SmsParams($this->messageFor($code, self::DEFAULT_MESSAGE), $number);

        if (!empty($this->options['from'])) {
            $params->setFrom((string) $this->options['from']);
        }

        try {
            $response = (new SmsResource($this->client))->dispatch($params);
        } catch (\Throwable $e) {
            throw new VerifierException('seven.io request failed: '.$e->getMessage(), 0, $e);
        }

        if ($response->getSuccess() !== self::SUCCESS) {
            throw new VerifierException(sprintf('seven.io refused the message: success code %d', $response->getSuccess()));
        }

        $message = $response->getMessages()[0] ?? null;
        $id = $message?->getId();

        return $id !== null ? (string) $id : 'seven-'.bin2hex(random_bytes(8));
    }

    private function cache(): CacheRepository
    {
        return $this->cache ?? cache()->store();
    }
}
