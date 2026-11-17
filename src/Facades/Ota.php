<?php

namespace Ikechukwu11\NativephpCustomOta\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static array status()
 * @method static string currentVersion()
 * @method static array check()
 * @method static array download(array $update)
 * @method static array apply(?string $version = null)
 * @method static array downloadAndApply(?array $update = null)
 * @method static array rollback()
 * @method static array prompt()
 *
 * @see \Ikechukwu11\NativephpCustomOta\Ota
 */
class Ota extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Ikechukwu11\NativephpCustomOta\Ota::class;
    }
}

