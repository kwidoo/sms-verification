<?php

namespace Kwidoo\SmsVerification\Contracts;

use Kwidoo\SmsVerification\Challenge\ChallengeRuntime;
use Kwidoo\SmsVerification\Exceptions\ConfigurationException;

/**
 * Builds a {@see ChallengeVerifierInterface} for one provider API from plain
 * configuration, per call.
 *
 * Unlike {@see \Kwidoo\SmsVerification\VerifierFactory}, which reads the
 * application's global config, a driver takes its configuration as an array,
 * so a host serving several tenants (each with its own credentials) can build
 * a verifier per request. The driver also describes the configuration it
 * understands and what must be redacted from logs, so the host needs no
 * provider knowledge of its own.
 */
interface ChallengeDriver
{
    /** Stable identifier the host selects the driver by, e.g. `telesign`. */
    public function name(): string;

    /**
     * @param  array<string, mixed>  $config  Keys as declared by options().
     *
     * @throws ConfigurationException
     */
    public function make(array $config, ChallengeRuntime $runtime): ChallengeVerifierInterface;

    /**
     * The configuration this driver reads.
     *
     * @return list<array{key: string, label: string, type: string, required: bool, sensitive: bool, description: string, default?: mixed}>
     */
    public function options(): array;

    /**
     * What a host must redact when it logs this driver's provider traffic.
     *
     * @return array{fields?: list<string>, words?: list<string>, patterns?: list<string>}
     */
    public function redaction(): array;
}
