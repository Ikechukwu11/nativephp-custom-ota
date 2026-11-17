<?php

namespace Ikechukwu11\NativephpCustomOta;

use Ikechukwu11\NativephpCustomOta\Events\RolledBack;
use Ikechukwu11\NativephpCustomOta\Events\UpdateApplied;
use Ikechukwu11\NativephpCustomOta\Events\UpdateAvailable;
use Ikechukwu11\NativephpCustomOta\Events\UpdateDownloaded;
use Ikechukwu11\NativephpCustomOta\Events\UpdateFailed;

class Ota
{
    private static bool $prompted = false;

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        return $this->call('Ota.GetStatus') ?? [
            'current_version' => '0.0.0',
            'pending' => false,
            'has_previous' => false,
        ];
    }

    public function currentVersion(): string
    {
        $status = $this->status();
        $version = $status['current_version'] ?? '0.0.0';

        return is_string($version) && $version !== '' ? $version : '0.0.0';
    }

    /**
     * Check the configured endpoint for a newer signed OTA release.
     *
     * @return array<string, mixed>
     */
    public function check(): array
    {
        $endpoint = config('mobile-ota.endpoint');

        if (! is_string($endpoint) || $endpoint === '') {
            $result = ['available' => false, 'reason' => 'OTA endpoint is not configured'];
            UpdateFailed::dispatch('check', $result['reason']);

            return $result;
        }

        $result = $this->call('Ota.Check', [
            'endpoint' => $endpoint,
            'channel' => (string) config('mobile-ota.channel', 'production'),
            'current_version' => $this->currentVersion(),
            'shell_fingerprint' => (string) config('mobile-ota.shell_fingerprint', ''),
            'token' => (string) config('mobile-ota.token', ''),
            'allow_insecure_http' => (bool) config('mobile-ota.allow_insecure_http', false),
        ]) ?? ['available' => false, 'reason' => 'Bridge call failed'];

        if (! empty($result['available'])) {
            UpdateAvailable::dispatch(
                version: (string) ($result['version'] ?? ''),
                url: (string) ($result['url'] ?? ''),
                sha256: (string) ($result['sha256'] ?? ''),
                size: (int) ($result['size'] ?? 0),
                releaseNotes: isset($result['release_notes']) ? (string) $result['release_notes'] : null,
                mandatory: (bool) ($result['mandatory'] ?? false),
            );
        } elseif (! empty($result['error'])) {
            UpdateFailed::dispatch('check', (string) $result['error']);
        }

        return $result;
    }

    /**
     * Download, verify (size, SHA-256, Ed25519 signature), and stage an update.
     *
     * @param  array<string, mixed>  $update
     * @return array<string, mixed>
     */
    public function download(array $update): array
    {
        $result = $this->call('Ota.Download', [
            'url' => (string) ($update['url'] ?? ''),
            'sha256' => (string) ($update['sha256'] ?? ''),
            'size' => (int) ($update['size'] ?? 0),
            'signature' => (string) ($update['signature'] ?? ''),
            'version' => (string) ($update['version'] ?? ''),
            'shell_fingerprint' => (string) ($update['shell_fingerprint'] ?? config('mobile-ota.shell_fingerprint', '')),
            'token' => (string) config('mobile-ota.token', ''),
            'allow_insecure_http' => (bool) config('mobile-ota.allow_insecure_http', false),
        ]) ?? ['success' => false, 'error' => 'Bridge call failed'];

        if (! empty($result['success'])) {
            UpdateDownloaded::dispatch(
                version: (string) ($update['version'] ?? ''),
                size: (int) ($update['size'] ?? 0),
            );
        } else {
            UpdateFailed::dispatch('download', (string) ($result['error'] ?? 'Download failed'));
        }

        return $result;
    }

    /**
     * Queue the staged pending.zip for extraction on the next app boot.
     *
     * @return array<string, mixed>
     */
    public function apply(?string $version = null): array
    {
        $result = $this->call('Ota.Apply', [
            'version' => $version ?? '',
        ]) ?? ['success' => false, 'error' => 'Bridge call failed'];

        if (! empty($result['success'])) {
            UpdateApplied::dispatch((string) ($result['version'] ?? $version ?? ''));
        } else {
            UpdateFailed::dispatch('apply', (string) ($result['error'] ?? 'Apply failed'));
        }

        return $result;
    }

    /**
     * Check (if no update array provided), download, verify, and queue for next boot.
     *
     * @param  array<string, mixed>|null  $update
     * @return array<string, mixed>
     */
    public function downloadAndApply(?array $update = null): array
    {
        if ($update === null) {
            $update = $this->check();

            if (empty($update['available'])) {
                return ['success' => false, 'reason' => $update['reason'] ?? 'No update available'];
            }
        }

        $downloaded = $this->download($update);

        if (empty($downloaded['success'])) {
            return $downloaded;
        }

        return $this->apply(isset($update['version']) ? (string) $update['version'] : null);
    }

    /**
     * Restore the previously staged update for next-boot apply.
     *
     * @return array<string, mixed>
     */
    public function rollback(): array
    {
        $result = $this->call('Ota.Rollback') ?? ['success' => false, 'error' => 'Bridge call failed'];

        if (! empty($result['success'])) {
            RolledBack::dispatch(isset($result['version']) ? (string) $result['version'] : null);
        } else {
            UpdateFailed::dispatch('rollback', (string) ($result['error'] ?? 'Rollback failed'));
        }

        return $result;
    }

    /**
     * Prompt the user with a native Later / Update dialog if an update is available.
     *
     * @return array<string, mixed>
     */
    public function prompt(): array
    {
        if (self::$prompted) {
            return ['prompted' => false, 'reason' => 'Already prompted this launch'];
        }

        self::$prompted = true;

        $endpoint = config('mobile-ota.endpoint');

        if (! is_string($endpoint) || $endpoint === '') {
            return ['prompted' => false, 'reason' => 'OTA endpoint is not configured'];
        }

        return $this->call('Ota.Prompt', [
            'endpoint' => $endpoint,
            'channel' => (string) config('mobile-ota.channel', 'production'),
            'current_version' => $this->currentVersion(),
            'shell_fingerprint' => (string) config('mobile-ota.shell_fingerprint', ''),
            'token' => (string) config('mobile-ota.token', ''),
            'allow_insecure_http' => (bool) config('mobile-ota.allow_insecure_http', false),
        ]) ?? ['prompted' => false];
    }

    /**
     * Execute automatic update behavior based on configured mode.
     */
    public function bootMode(): void
    {
        $mode = config('mobile-ota.mode', 'manual');

        if ($mode === 'prompt') {
            $this->prompt();
        } elseif ($mode === 'silent') {
            $this->downloadAndApply();
        }
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>|null
     */
    protected function call(string $function, array $parameters = []): ?array
    {
        if (! function_exists('nativephp_call')) {
            return null;
        }

        $raw = nativephp_call($function, json_encode($parameters) ?: '{}');

        if (is_array($raw)) {
            return $raw;
        }

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }
}

