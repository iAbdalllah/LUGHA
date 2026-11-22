<?php

namespace App\Console\Commands;

use App\Domain\Delivery\SigningKey;
use Illuminate\Console\Command;

class GenerateSigningKey extends Command
{
    protected $signature = 'lugha:signing-key {--key-id= : Key id, default pk-YYYY-MM} {--force : Replace an existing key}';

    protected $description = 'Generate the Ed25519 key that signs packages and licenses (development/staging; use a KMS in production)';

    public function handle(): int
    {
        $path = SigningKey::path();
        if (is_file($path) && ! $this->option('force')) {
            $this->error("A signing key already exists at $path (use --force to replace it; existing packages would stop verifying).");

            return self::FAILURE;
        }
        @mkdir(dirname($path), 0700, true);
        $seed = random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES);
        $public = sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($seed));
        $keyId = $this->option('key-id') ?: 'pk-'.now()->format('Y-m');
        file_put_contents($path, json_encode(['key_id' => $keyId, 'secret' => base64_encode($seed), 'public' => base64_encode($public)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        chmod($path, 0600);
        $this->info("Signing key $keyId written to $path");
        $this->line('Public key (pin this in the apps): '.base64_encode($public));

        return self::SUCCESS;
    }
}
