<?php

namespace Ikechukwu11\NativephpCustomOta\Commands;

use Illuminate\Console\Command;

class SignReleaseCommand extends Command
{
    protected $signature = 'ota:sign
        {zip : Path to the OTA release zip}
        {--release-version= : Semantic version of this release (e.g. 1.0.1)}
        {--url= : Public HTTPS URL where the zip will be hosted}
        {--channel=production : Release channel (production, staging, testing)}
        {--shell-fingerprint= : Native shell fingerprint this release targets}
        {--notes= : Optional release notes}
        {--mandatory : Mark this update as mandatory}
        {--key= : Base64 Ed25519 private key (defaults to .ota-private.key or NATIVEPHP_OTA_PRIVATE_KEY)}
        {--manifest=manifest.json : Output manifest JSON path}';

    protected $description = 'Sign an OTA release zip with Ed25519 and generate/update manifest.json';

    public function handle(): int
    {
        $zipPath = $this->argument('zip');

        if (! is_file($zipPath)) {
            $this->error("Zip file not found: {$zipPath}");

            return self::FAILURE;
        }

        $version = (string) $this->option('release-version');

        if ($version === '') {
            $this->error('--release-version is required (e.g. --release-version=1.0.1)');

            return self::FAILURE;
        }

        $secretKeyB64 = $this->option('key')
            ?: env('NATIVEPHP_OTA_PRIVATE_KEY')
            ?: (is_file(base_path('.ota-private.key')) ? trim((string) file_get_contents(base_path('.ota-private.key'))) : '');

        if ($secretKeyB64 === '') {
            $this->error('Private key not found. Run `php artisan ota:keys` or pass --key=');

            return self::FAILURE;
        }

        $secretKey = base64_decode($secretKeyB64, true);

        if ($secretKey === false || strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            $this->error('Invalid Ed25519 private key.');

            return self::FAILURE;
        }

        $sha256 = hash_file('sha256', $zipPath);
        $size = filesize($zipPath);
        $shellFingerprint = (string) ($this->option('shell-fingerprint') ?? config('mobile-ota.shell_fingerprint', ''));

        // Canonical message signed by Ed25519: "<sha256>|<version>|<shell_fingerprint>"
        $canonical = "{$sha256}|{$version}|{$shellFingerprint}";
        $signature = base64_encode(sodium_crypto_sign_detached($canonical, $secretKey));

        $manifestPath = $this->option('manifest');
        $manifest = is_file($manifestPath)
            ? (json_decode((string) file_get_contents($manifestPath), true) ?: [])
            : [];

        $channel = (string) $this->option('channel');
        $manifest['channels'][$channel] = [
            'version' => $version,
            'url' => (string) ($this->option('url') ?? ''),
            'sha256' => $sha256,
            'size' => $size,
            'signature' => $signature,
            'shell_fingerprint' => $shellFingerprint,
            'mandatory' => (bool) $this->option('mandatory'),
            'release_notes' => $this->option('notes'),
            'published_at' => gmdate('c'),
        ];

        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $this->info("Signed {$zipPath} (v{$version}) for channel [{$channel}].");
        $this->line("SHA-256:   {$sha256}");
        $this->line("Size:      {$size} bytes");
        $this->line("Signature: {$signature}");
        $this->line("Manifest:  {$manifestPath}");

        return self::SUCCESS;
    }
}

