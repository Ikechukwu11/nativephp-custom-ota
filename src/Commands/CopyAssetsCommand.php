<?php

namespace Ikechukwu11\NativephpCustomOta\Commands;

use Native\Mobile\Plugins\Commands\NativePluginHookCommand;

class CopyAssetsCommand extends NativePluginHookCommand
{
    protected $signature = 'nativephp:custom-ota:copy-assets';

    protected $description = 'Bake OTA public key and shell fingerprint into native app assets';

    public function handle(): int
    {
        $payload = json_encode([
            'public_key' => (string) config('mobile-ota.public_key', ''),
            'shell_fingerprint' => (string) config('mobile-ota.shell_fingerprint', ''),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $tempFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ota-config.json';
        file_put_contents($tempFile, $payload);

        if ($this->isAndroid()) {
            $this->copyToAndroidAssets($tempFile, 'ota-config.json');
            $this->info('Baked ota-config.json into Android assets.');
        }

        if ($this->isIos()) {
            $this->copyToIosBundle($tempFile, 'ota-config.json');
            $this->info('Baked ota-config.json into iOS bundle.');
        }

        @unlink($tempFile);

        return self::SUCCESS;
    }
}

