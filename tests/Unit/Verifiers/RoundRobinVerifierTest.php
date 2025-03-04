<?php

namespace Kwidoo\SmsVerification\Tests\Unit\Verifiers;

use Illuminate\Support\Facades\Cache;
use Mockery;
use Kwidoo\SmsVerification\Verifiers\RoundRobinVerifier;
use Kwidoo\SmsVerification\Contracts\VerifierInterface;
use Kwidoo\SmsVerification\Exceptions\VerifierException;
use Kwidoo\SmsVerification\Tests\TestCase;

class RoundRobinVerifierTest extends TestCase
{
    public function testCreateCachesVerifierIndexAndDelegatesCreate()
    {
        // Create two dummy verifiers with expectations.
        $dummyVerifier1 =  Mockery::mock(VerifierInterface::class);;
        $dummyVerifier1->shouldReceive('create')
            ->once()
            ->with('+1234567890');
        $dummyVerifier1->shouldReceive('sanitizePhoneNumber')
            ->andReturn('+1234567890');

        $dummyVerifier2 =  Mockery::mock(VerifierInterface::class);;
        // We won't expect create() to be called on the second one in this test.

        $verifiers = [$dummyVerifier1, $dummyVerifier2];

        // Ensure the round robin cache is reset.
        Cache::flush();
        // Set the cache key config values.
        config([
            'sms-verification.round_robin.current_verifier_cache_key' => 'round_robin_verifiers_',
            'sms-verification.round_robin.verifier_for_number_cache_key' => 'round_robin_verifier_for_',
        ]);

        $roundRobin = new RoundRobinVerifier($verifiers);
        $roundRobin->create('1234567890');

        // Check that the verifier index was cached for the sanitized number.
        $cachedIndex = Cache::get('round_robin_verifier_for_+1234567890');
        $this->assertNotNull($cachedIndex);
        $this->assertIsInt($cachedIndex);
    }

    public function testValidateThrowsExceptionWhenNoCachedVerifierIndex()
    {
        $dummyVerifier =  Mockery::mock(VerifierInterface::class);;
        $dummyVerifier->shouldReceive('sanitizePhoneNumber')
            ->andReturn('+1234567890');

        $roundRobin = new RoundRobinVerifier([$dummyVerifier]);
        Cache::flush();

        $this->expectException(VerifierException::class);
        $roundRobin->validate(['1234567890', '1234']);
    }

    public function testValidateDelegatesToCorrectVerifier()
    {
        // Create two dummy verifiers.
        $dummyVerifier1 =  Mockery::mock(VerifierInterface::class);;
        $dummyVerifier1->shouldReceive('sanitizePhoneNumber')
            ->andReturn('+1234567890');
        // In this test, we will simulate that the cached index points to verifier 1.
        $dummyVerifier1->shouldReceive('validate')
            ->once()
            ->with(['+1234567890', '1234'])
            ->andReturn(true);

        $dummyVerifier2 =  Mockery::mock(VerifierInterface::class);;
        $dummyVerifier2->shouldReceive('sanitizePhoneNumber')
            ->andReturn('+1234567890');

        $verifiers = [$dummyVerifier1, $dummyVerifier2];

        // Cache the verifier index manually.
        config([
            'sms-verification.round_robin.verifier_for_number_cache_key' => 'round_robin_verifier_for_',
        ]);
        Cache::put('round_robin_verifier_for_+1234567890', 0, now()->addMinutes(10));

        $roundRobin = new RoundRobinVerifier($verifiers);
        $result = $roundRobin->validate(['1234567890', '1234']);

        $this->assertTrue($result);
    }
}
