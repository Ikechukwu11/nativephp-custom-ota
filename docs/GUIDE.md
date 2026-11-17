# Step-by-Step Guide: NativePHP Custom OTA

This guide explains in plain, straightforward steps how to:
1. **Host the plugin itself on GitHub** so any of your projects can install it and receive future plugin updates.
2. **Enable OTA in any NativePHP Mobile project** (using GitHub Releases for now, with the option to switch to any other server later).
3. **Publish an OTA update** whenever you change your app's PHP/views/assets.

---

## Part 1: Put This Plugin on GitHub (One-Time Setup)

To reuse this plugin across all your projects and get future updates via `composer update`:

1. Create a **public** GitHub repository named `nativephp-custom-ota` under your account (`ikechukwu11/nativephp-custom-ota`).
2. Push the contents of `packages/nativephp-custom-ota` to that repository:
   ```bash
   cd packages/nativephp-custom-ota
   git init
   git add .
   git commit -m "Initial release v1.0.0"
   git branch -M main
   git remote add origin https://github.com/ikechukwu11/nativephp-custom-ota.git
   git push -u origin main
   ```
3. **Tag your first release** (this is how Composer tracks versions):
   ```bash
   git tag v1.0.0
   git push origin v1.0.0
   ```
4. **Getting future plugin updates:** Whenever you improve the plugin later, push your changes and create a new tag (e.g. `v1.0.1`). In any project using the plugin, just run:
   ```bash
   composer update ikechukwu11/nativephp-custom-ota
   ```

---

## Part 2: Install the Plugin in Any Project

In **any** NativePHP Mobile project where you want OTA:

1. Open that project's `composer.json` and add your GitHub repo under `"repositories"`:
   ```json
   "repositories": [
       {
           "type": "vcs",
           "url": "https://github.com/ikechukwu11/nativephp-custom-ota"
       }
   ]
   ```
2. Install and register the plugin:
   ```bash
   composer require ikechukwu11/nativephp-custom-ota:^1.0
   php artisan vendor:publish --tag=nativephp-plugins-provider
   php artisan native:plugin:register ikechukwu11/nativephp-custom-ota
   php artisan vendor:publish --tag=mobile-ota-config
   ```

---

## Part 3: Enable OTA in Your Project (Checklist)

- **Step 1: Generate your signing keys (one time per app)**
  ```bash
  php artisan ota:keys
  ```
  - This creates `.ota-private.key` (keep this secret, **never** commit it to Git) and prints `NATIVEPHP_OTA_PUBLIC_KEY=...`.
- **Step 2: Add these lines to your `.env`**
  ```env
  # "prompt" = asks user Later/Update | "silent" = downloads in background | "manual" = you trigger it in PHP
  NATIVEPHP_OTA_MODE=prompt

  # Using GitHub Releases for now (you can swap this URL to S3, Cloudflare, or your own Laravel API anytime)
  NATIVEPHP_OTA_ENDPOINT=https://github.com/YOUR_USERNAME/YOUR_APP_REPO/releases/latest/download/manifest.json

  NATIVEPHP_OTA_CHANNEL=production
  NATIVEPHP_OTA_PUBLIC_KEY=paste-your-public-key-here
  NATIVEPHP_OTA_SHELL_FINGERPRINT=1.0.0-shell
  ```
- **Step 3: Build your native app**
  Run `php artisan native:run`. During the build, the plugin automatically bakes your `public_key` and `shell_fingerprint` into the native app so attackers cannot bypass them.

> **Switching endpoints later:** You don't need to change any code to move off GitHub Releases. Just update `NATIVEPHP_OTA_ENDPOINT` in `.env` to your S3/R2/Laravel API URL and ship the update.

---

## Part 4: How to Publish an OTA Update

Whenever you want to push new PHP code, Blade views, or assets to users' phones without a store review:

1. **Bundle your app into a `.zip`:**
   ```bash
   php artisan ota:bundle
   ```
   This creates `storage/app/ota/release.zip`.

2. **Sign the `.zip` and generate `manifest.json`:**
   ```bash
   php artisan ota:sign storage/app/ota/release.zip \
     --release-version=1.0.1 \
     --url=https://github.com/YOUR_USERNAME/YOUR_APP_REPO/releases/download/v1.0.1/release.zip \
     --channel=production
   ```

3. **Upload to GitHub Releases:**
   - Go to your app's GitHub repo → **Releases** → **Draft a new release**.
   - Set the tag to `v1.0.1`.
   - Attach **both** `release.zip` and `manifest.json`.
   - Publish the release.

That's it! Next time users open the app, it will check `manifest.json`, verify the signature, download `release.zip`, and apply it on the next app restart.
