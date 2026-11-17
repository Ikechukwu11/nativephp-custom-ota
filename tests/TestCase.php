<?php

namespace Ikechukwu11\NativephpCustomOta\Tests;

use Ikechukwu11\NativephpCustomOta\OtaServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            OtaServiceProvider::class,
        ];
    }
}

