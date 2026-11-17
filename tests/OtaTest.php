<?php

use Ikechukwu11\NativephpCustomOta\Events\RolledBack;
use Ikechukwu11\NativephpCustomOta\Events\UpdateApplied;
use Ikechukwu11\NativephpCustomOta\Events\UpdateAvailable;
use Ikechukwu11\NativephpCustomOta\Events\UpdateDownloaded;
use Ikechukwu11\NativephpCustomOta\Events\UpdateFailed;
use Ikechukwu11\NativephpCustomOta\Facades\Ota;
use Ikechukwu11\NativephpCustomOta\Testing\OtaMacros;
use Illuminate\Support\Facades\Event;

it('generates an Ed25519 keypair and signs a release zip', function () {
    $tempZip = sys_get_temp_dir().'/test-ota-release.zip';
    $manifestPath = sys_get_temp_dir().'/test-ota-manifest.json';
    file_put_contents($tempZip, 'fake-zip-content');

    $keypair = sodium_crypto_sign_keypair();
    $pubB64 = base64_encode(sodium_crypto_sign_publickey($keypair));
    $secB64 = base64_encode(sodium_crypto_sign_secretkey($keypair));

    $this->artisan('ota:sign', [
        'zip' => $tempZip,
        '--release-version' => '1.2.3',
        '--url' => 'https://example.com/release.zip',
        '--channel' => 'production',
        '--shell-fingerprint' => 'fp-abc',
        '--key' => $secB64,
        '--manifest' => $manifestPath,
    ])->assertSuccessful();

    $manifest = json_decode(file_get_contents($manifestPath), true);
    $release = $manifest['channels']['production'];

    expect($release['version'])->toBe('1.2.3')
        ->and($release['url'])->toBe('https://example.com/release.zip')
        ->and($release['shell_fingerprint'])->toBe('fp-abc');

    $canonical = "{$release['sha256']}|1.2.3|fp-abc";
    $valid = sodium_crypto_sign_verify_detached(
        base64_decode($release['signature']),
        $canonical,
        base64_decode($pubB64)
    );

    expect($valid)->toBeTrue();

    @unlink($tempZip);
    @unlink($manifestPath);
});

it('dispatches UpdateAvailable, UpdateDownloaded, and UpdateApplied on downloadAndApply', function () {
    Event::fake();
    config()->set('mobile-ota.endpoint', 'https://example.com/manifest.json');

    OtaMacros::fake([
        'Ota.Check' => [
            'available' => true,
            'version' => '1.0.1',
            'url' => 'https://example.com/1.0.1.zip',
            'sha256' => str_repeat('a', 64),
            'size' => 1024,
            'signature' => 'sig',
        ],
    ]);

    $result = Ota::downloadAndApply();

    expect($result['success'])->toBeTrue();

    Event::assertDispatched(UpdateAvailable::class, fn ($e) => $e->version === '1.0.1');
    Event::assertDispatched(UpdateDownloaded::class, fn ($e) => $e->version === '1.0.1');
    Event::assertDispatched(UpdateApplied::class, fn ($e) => $e->version === '1.0.1');
});

it('dispatches UpdateFailed when endpoint is missing', function () {
    Event::fake();
    config()->set('mobile-ota.endpoint', null);

    $result = Ota::check();

    expect($result['available'])->toBeFalse();
    Event::assertDispatched(UpdateFailed::class, fn ($e) => $e->stage === 'check');
});

it('dispatches RolledBack on rollback', function () {
    Event::fake();
    OtaMacros::fake();

    $result = Ota::rollback();

    expect($result['success'])->toBeTrue();
    Event::assertDispatched(RolledBack::class);
});

