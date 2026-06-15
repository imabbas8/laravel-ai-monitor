<?php

namespace Debug\AiHealth\Tests;

use Debug\AiHealth\AiHealthServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            AiHealthServiceProvider::class,
        ];
    }
}
