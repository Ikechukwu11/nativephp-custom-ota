<?php

namespace Ikechukwu11\NativephpCustomOta;

use Ikechukwu11\NativephpCustomOta\Commands\BundleCommand;
use Ikechukwu11\NativephpCustomOta\Commands\CopyAssetsCommand;
use Ikechukwu11\NativephpCustomOta\Commands\GenerateKeysCommand;
use Ikechukwu11\NativephpCustomOta\Commands\SignReleaseCommand;
use Illuminate\Support\ServiceProvider;

class OtaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/mobile-ota.php', 'mobile-ota');

        $this->app->singleton(Ota::class, fn () => new Ota());
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/mobile-ota.php' => config_path('mobile-ota.php'),
            ], 'mobile-ota-config');

            $this->commands([
                GenerateKeysCommand::class,
                BundleCommand::class,
                SignReleaseCommand::class,
                CopyAssetsCommand::class,
            ]);
        }

        if (function_exists('nativephp_call')) {
            $this->app->booted(function (): void {
                $this->app->make(Ota::class)->bootMode();
            });
        }
    }
}

