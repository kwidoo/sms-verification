<?php

namespace Kwidoo\SmsVerification\Challenge;

use Kwidoo\SmsVerification\Challenge\Drivers\PlivoDriver;
use Kwidoo\SmsVerification\Challenge\Drivers\SevenDriver;
use Kwidoo\SmsVerification\Challenge\Drivers\SinchDriver;
use Kwidoo\SmsVerification\Challenge\Drivers\TelesignDriver;
use Kwidoo\SmsVerification\Challenge\Drivers\TelesignSmsVerifyDriver;
use Kwidoo\SmsVerification\Challenge\Drivers\TelesignVerifyDriver;
use Kwidoo\SmsVerification\Challenge\Drivers\TelnyxDriver;
use Kwidoo\SmsVerification\Challenge\Drivers\TwilioDriver;
use Kwidoo\SmsVerification\Challenge\Drivers\VonageDriver;
use Kwidoo\SmsVerification\Contracts\ChallengeDriver;
use Kwidoo\SmsVerification\Contracts\ChallengeVerifierInterface;
use Kwidoo\SmsVerification\Exceptions\ConfigurationException;

/**
 * The challenge drivers a host can choose from, by name.
 *
 * `defaults()` registers every driver this package ships. A host can pass its
 * own list (or `with()` an extra driver) without the package knowing about it.
 */
final class ChallengeDrivers
{
    /** @var array<string, ChallengeDriver> */
    private array $drivers = [];

    /**
     * @param  iterable<ChallengeDriver>  $drivers  The first one is the default.
     */
    public function __construct(iterable $drivers)
    {
        foreach ($drivers as $driver) {
            $this->drivers[$driver->name()] = $driver;
        }
    }

    public static function defaults(): self
    {
        return new self([
            new TelesignDriver(),
            new TelesignVerifyDriver(),
            new TelesignSmsVerifyDriver(),
            new TwilioDriver(),
            new VonageDriver(),
            new TelnyxDriver(),
            new PlivoDriver(),
            new SinchDriver(),
            new SevenDriver(),
        ]);
    }

    public function with(ChallengeDriver $driver): self
    {
        return new self([...array_values($this->drivers), $driver]);
    }

    /**
     * @throws ConfigurationException
     */
    public function make(string $name, array $config, ChallengeRuntime $runtime = new ChallengeRuntime()): ChallengeVerifierInterface
    {
        return $this->get($name)->make($config, $runtime);
    }

    /**
     * @throws ConfigurationException
     */
    public function get(string $name): ChallengeDriver
    {
        return $this->drivers[strtolower(trim($name))]
            ?? throw ConfigurationException::unknownDriver($name, $this->names());
    }

    public function has(string $name): bool
    {
        return isset($this->drivers[strtolower(trim($name))]);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->drivers);
    }

    public function defaultName(): ?string
    {
        return array_key_first($this->drivers);
    }

    /**
     * Every option any driver reads, merged by key, for a host that publishes
     * one configuration form for all drivers. An option is required only when
     * every driver requires it, sensitive when any driver says so, and its
     * description names the drivers it applies to.
     *
     * @return list<array{key: string, label: string, type: string, required: bool, sensitive: bool, description: string, default?: mixed}>
     */
    public function options(): array
    {
        $merged = [];
        $users = [];

        foreach ($this->drivers as $name => $driver) {
            foreach ($driver->options() as $option) {
                $key = $option['key'];
                $users[$key][$name] = $option;

                if (!isset($merged[$key])) {
                    $merged[$key] = $option;

                    continue;
                }

                $merged[$key]['sensitive'] = $merged[$key]['sensitive'] || $option['sensitive'];
            }
        }

        $all = count($this->drivers);
        $result = [];

        foreach ($merged as $key => $option) {
            $option['required'] = count($users[$key]) === $all
                && !in_array(false, array_column($users[$key], 'required'), true);

            if (count($users[$key]) > 1) {
                $defaults = array_unique(array_map(
                    static fn ($o) => json_encode($o['default'] ?? null),
                    $users[$key]
                ));

                $option['description'] = implode(' ', array_map(
                    static fn (string $driver, array $o) => sprintf('%s: %s', $driver, $o['description']),
                    array_keys($users[$key]),
                    $users[$key]
                ));

                if (count($defaults) > 1) {
                    unset($option['default']);
                }
            } elseif ($all > 1) {
                $option['description'] = sprintf('%s only: %s', array_key_first($users[$key]), $option['description']);
            }

            if ($option['sensitive']) {
                unset($option['default']);
            }

            $result[] = $option;
        }

        return $result;
    }

    /**
     * @return list<string> Keys of options any driver marks sensitive.
     */
    public function sensitiveKeys(): array
    {
        return array_values(array_map(
            static fn (array $option) => $option['key'],
            array_filter($this->options(), static fn (array $option) => $option['sensitive'])
        ));
    }

    /**
     * @return array{fields: list<string>, words: list<string>, patterns: list<string>}
     */
    public function redaction(): array
    {
        $result = ['fields' => [], 'words' => [], 'patterns' => []];

        foreach ($this->drivers as $driver) {
            foreach ($driver->redaction() as $kind => $values) {
                $result[$kind] = array_values(array_unique([...$result[$kind] ?? [], ...$values]));
            }
        }

        return $result;
    }
}
