# NativePHP Custom OTA (`ikechukwu11/nativephp-custom-ota`)

Self-hosted, Bifrost-independent Over-The-Air (OTA) PHP payload updates for **NativePHP Mobile v4** (iOS & Android).

- **Zero Bifrost dependency:** You own the Ed25519 keys, the update server, and the release pipeline.
- **Host anywhere:** Works with static `manifest.json` + `.zip` (GitHub Releases, S3/R2, Cloudflare) **or** a custom Laravel API endpoint.
- **Security-first:** Ed25519 public key & `shell_fingerprint` are baked into the native binary at build time (`copy_assets`). Downloads are verified in Swift/Kotlin for exact byte size, SHA-256, and Ed25519 signature before staging.

## Installation

### From GitHub (across any project)

Add the repository to your project's `composer.json` (or install directly once published to Packagist):

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/ikechukwu11/nativephp-custom-ota"
        }
    ]
}
```

Then install and register the plugin:

```bash
composer require ikechukwu11/nativephp-custom-ota
php artisan vendor:publish --tag=nativephp-plugins-provider
php artisan native:plugin:register ikechukwu11/nativephp-custom-ota
php artisan vendor:publish --tag=mobile-ota-config
```

## Quick Start

1. **Generate an Ed25519 keypair:**
   ```bash
   php artisan ota:keys
   ```
   Add `NATIVEPHP_OTA_PUBLIC_KEY=...` to your `.env` (and CI secrets). **Never commit `.ota-private.key`**.

2. **Configure `.env`:**
   ```env
   NATIVEPHP_OTA_MODE=prompt
   NATIVEPHP_OTA_ENDPOINT=https://github.com/your-org/your-app/releases/latest/download/manifest.json
   NATIVEPHP_OTA_CHANNEL=production
   NATIVEPHP_OTA_PUBLIC_KEY=your-base64-public-key
   NATIVEPHP_OTA_SHELL_FINGERPRINT=1.0.0-shell
   ```

3. **Bundle & sign an OTA release:**
   ```bash
   php artisan ota:bundle
   php artisan ota:sign storage/app/ota/release.zip \
     --release-version=1.0.1 \
     --url=https://github.com/your-org/your-app/releases/download/v1.0.1/release.zip \
     --channel=production
   ```

4. **Upload** `release.zip` and `manifest.json` to your GitHub Release, S3/R2 bucket, or Laravel API.

## Usage in PHP

```php
use Ikechukwu11\NativephpCustomOta\Facades\Ota;

// Manual check + download + stage for next boot
$result = Ota::downloadAndApply();

// Or step-by-step:
$update = Ota::check();
if ($update['available']) {
    Ota::download($update);
    Ota::apply($update['version']);
}

// Rollback to previous staged zip
Ota::rollback();

// Inspect status
$status = Ota::status(); // ['current_version' => '1.0.0', 'pending' => false, 'has_previous' => true]
```

See [docs/GUIDE.md](docs/GUIDE.md) for the full architecture, security model, and hosting examples (GitHub Releases + Custom Laravel API).

