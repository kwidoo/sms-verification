<?php

namespace Kwidoo\SmsVerification\Tests\Unit\Console\Commands;

use Mockery;
use Kwidoo\SmsVerification\Tests\TestCase;
use Illuminate\Support\Facades\File;

class CreateSmsVerifierCommandTest extends TestCase
{
    /** @test */
    public function testCreatesVerifierClass()
    {
        // Stub File facade calls.
        File::shouldReceive('exists')->andReturn(false);
        File::shouldReceive('isDirectory')->andReturn(false);
        File::shouldReceive('makeDirectory')->once()->andReturnTrue();
        File::shouldReceive('put')->once()->withArgs(function ($path, $content) {
            return strpos($content, 'class CustomVerifier') !== false;
        });

        $this->artisan('verifier:create-sms-verifier', ['name' => 'CustomVerifier'])
            ->expectsQuestion('Enter the client class (including namespace) for this verifier', 'App\Client')
            ->assertExitCode(0);
    }

    /** @test */
    public function testHandlesExistingFile()
    {
        File::shouldReceive('exists')->andReturn(true);
        File::shouldReceive('isDirectory')->andReturn(false);
        File::shouldReceive('makeDirectory')->once()->andReturnTrue();


        $this->artisan('verifier:create-sms-verifier', ['name' => 'ExistingVerifier'])
            ->expectsOutput('A verifier with the name ExistingVerifier already exists.');
    }
}
