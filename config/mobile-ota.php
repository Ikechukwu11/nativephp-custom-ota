<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Update Mode
    |--------------------------------------------------------------------------
    |
    | - "manual" (default): Your app calls Ota::check() / Ota::downloadAndApply().
    | - "prompt": Checks once per launch and shows a native Later / Update dialog.
    | - "silent": Checks and downloads automatically in the background.
    |
    */
    'mode' => env('NATIVEPHP_OTA_MODE', 'manual'),

    /*
    |--------------------------------------------------------------------------
    | Update Endpoint
    |--------------------------------------------------------------------------
    |
    | HTTPS URL to either a static manifest.json (e.g. GitHub Releases, S3, R2)
    | or a custom Laravel API endpoint.
    |
    */
    'endpoint' => env('NATIVEPHP_OTA_ENDPOINT'),

    /*
    |--------------------------------------------------------------------------
    | Release Channel
    |--------------------------------------------------------------------------
    |
    | Channel this build follows (e.g. "production", "staging", "testing").
    |
    */
    'channel' => env('NATIVEPHP_OTA_CHANNEL', env('APP_ENV', 'production')),

    /*
    |--------------------------------------------------------------------------
    | Ed25519 Public Key (Base64)
    |--------------------------------------------------------------------------
    |
    | Generated via `php artisan ota:keys`. Baked into the native binary during
    | build (`copy_assets`) so signature verification cannot be bypassed in PHP.
    |
    */
    'public_key' => env('NATIVEPHP_OTA_PUBLIC_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Shell Fingerprint
    |--------------------------------------------------------------------------
    |
    | Identifies the compiled native binary (plugins, NativePHP core version).
    | Updates with a mismatched shell_fingerprint are rejected to prevent
    | incompatible PHP payloads from crashing an older binary.
    |
    */
    'shell_fingerprint' => env('NATIVEPHP_OTA_SHELL_FINGERPRINT'),

    /*
    |--------------------------------------------------------------------------
    | Optional Bearer Token
    |--------------------------------------------------------------------------
    |
    | Sent as `Authorization: Bearer <token>` when fetching from private
    | endpoints or private GitHub/API servers.
    |
    */
    'token' => env('NATIVEPHP_OTA_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Allow Insecure HTTP (Local Development Only)
    |--------------------------------------------------------------------------
    |
    | Native code rejects non-HTTPS URLs by default. Set to true only when
    | testing against a local HTTP server with APP_DEBUG=true.
    |
    */
    'allow_insecure_http' => (bool) env('NATIVEPHP_OTA_ALLOW_HTTP', false),
];

