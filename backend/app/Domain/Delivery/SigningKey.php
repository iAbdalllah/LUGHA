<?php

namespace App\Domain\Delivery;

use RuntimeException;

/**
 * The Ed25519 key that signs package manifests and licenses (the same file lugha-pkg uses).
 * Format: {"key_id": "...", "secret": base64(32-byte seed), "public": base64(32)}.
 * Production: keep this in a KMS/HSM and sign there; the file path is for development and staging.
 */
final class SigningKey
{
    private function __construct(public readonly string $keyId, private readonly string $seed, public readonly string $public) {}

    public static function path(): string
    {
        return (string) config('lugha.signing_key_path');
    }

    public static function load(): self
    {
        $path = self::path();
        if (! is_file($path)) {
            throw new RuntimeException("Signing key not found at $path — run `php artisan lugha:signing-key`.");
        }
        $k = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $seed = base64_decode($k['secret'] ?? '', true);
        if ($seed === false || strlen($seed) !== SODIUM_CRYPTO_SIGN_SEEDBYTES) {
            throw new RuntimeException('Signing key file is invalid.');
        }

        return new self($k['key_id'], $seed, $k['public']);
    }

    public function sign(string $message): string
    {
        $pair = sodium_crypto_sign_seed_keypair($this->seed);

        return sodium_crypto_sign_detached($message, sodium_crypto_sign_secretkey($pair));
    }
}
