<?php

namespace Kwidoo\SmsVerification\Challenge\Drivers;

use Kwidoo\SmsVerification\Challenge\Drivers\Concerns\DriverSupport;
use Kwidoo\SmsVerification\Exceptions\ConfigurationException;

/**
 * Shared by the Telesign drivers.
 */
trait TelesignDriverSupport
{
    use DriverSupport;

    /**
     * @return array{0: string, 1: string}
     *
     * @throws ConfigurationException
     */
    private function credentials(array $config): array
    {
        return [$this->required($config, 'customer_id'), $this->required($config, 'api_key')];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function credentialOptions(): array
    {
        return [
            self::option('customer_id', 'Telesign customer ID', 'Customer ID from the Telesign portal.', required: true),
            self::option('api_key', 'Telesign API key', 'API key from the Telesign portal.', required: true, sensitive: true),
        ];
    }
}
