<?php

namespace Ikechukwu11\NativephpCustomOta\Commands;

use Illuminate\Console\Command;

class GenerateKeysCommand extends Command
{
    protected $signature = 'ota:keys {--force : Overwrite existing private key file}';

    protected $description = 'Generate an Ed25519 keypair for signing NativePHP Custom OTA releases';

    public function handle(): int
    {
        $keypair = sodium_crypto_sign_keypair();
        $publicKey = base64_encode(sodium_crypto_sign_publickey($keypair));
        $secretKey = base64_encode(sodium_crypto_sign_secretkey($keypair));

        $privateKeyPath = base_path('.ota-private.key');

        if (file_exists($privateKeyPath) && ! $this->option('force')) {
            $this->warn('A .ota-private.key file already exists. Use --force to overwrite.');
            $this->line('Public Key (NATIVEPHP_OTA_PUBLIC_KEY): '.$publicKey);

            return self::SUCCESS;
        }

        file_put_contents($privateKeyPath, $secretKey);
        @chmod($privateKeyPath, 0600);

        $this->info('Ed25519 keypair generated.');
        $this->newLine();
        $this->line('<comment>1. Add this to your .env (and CI build secrets):</comment>');
        $this->line('NATIVEPHP_OTA_PUBLIC_KEY='.$publicKey);
        $this->newLine();
        $this->line('<comment>2. Private key saved to:</comment> '.$privateKeyPath);
        $this->warn('NEVER commit .ota-private.key to git! Add it to .gitignore and store it in your CI/CD secrets.');

        return self::SUCCESS;
    }
}

