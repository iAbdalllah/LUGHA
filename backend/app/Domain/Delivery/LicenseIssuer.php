<?php

namespace App\Domain\Delivery;

use App\Models\Device;
use App\Models\Entitlement;
use App\Models\License;
use App\Models\PackageVersion;
use RuntimeException;

/**
 * Issues device licenses (docs/03-api.md). Must stay byte-compatible with lugha-core:
 *   wrapped = eph_public(32) || nonce(12) || AES-256-GCM(kek, content_key) || tag(16)
 *   kek     = HKDF-SHA256(ikm = X25519(eph, device_public), salt = eph_public || device_public, info = "lugha-license-v1")
 *   license = {v:1, payload: base64(payload JSON bytes), key_id, sig: base64(Ed25519(payload bytes))}
 */
final class LicenseIssuer
{
    public const WRAP_INFO = 'lugha-license-v1';

    public static function wrapKey(string $contentKey, string $devicePublic): string
    {
        if (strlen($contentKey) !== 32 || strlen($devicePublic) !== 32) {
            throw new RuntimeException('content key and device key must be 32 bytes');
        }
        $eph = random_bytes(32);
        $ephPublic = sodium_crypto_scalarmult_base($eph);
        $shared = sodium_crypto_scalarmult($eph, $devicePublic);
        $kek = hash_hkdf('sha256', $shared, 32, self::WRAP_INFO, $ephPublic.$devicePublic);
        $nonce = random_bytes(12);
        $cipher = openssl_encrypt($contentKey, 'aes-256-gcm', $kek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        sodium_memzero($eph);

        return $ephPublic.$nonce.$cipher.$tag;
    }

    public function issue(Entitlement $entitlement, Device $device, PackageVersion $version): array
    {
        if ($device->status !== 'active' || $device->user_id !== $entitlement->user_id) {
            throw new RuntimeException('Device is not active on this account.');
        }
        if (! $entitlement->covers($version)) {
            throw new RuntimeException('This version is not covered by the purchase.');
        }
        $contentKey = $version->contentKey() ?? throw new RuntimeException('Version has no content key (not built).');

        $license = License::create([
            'entitlement_id' => $entitlement->id, 'device_id' => $device->id, 'package_version_id' => $version->id,
            'expires_at' => now()->addDays((int) config('lugha.license_days', 60)),
        ]);

        $payload = json_encode([
            'v' => 1,
            'license_id' => $license->public_id,
            'user' => $entitlement->user->public_id,
            'device_fp' => $device->key_fingerprint,
            'package_id' => $version->package->slug,
            'version' => $version->version,
            'content_key_wrapped' => base64_encode(self::wrapKey($contentKey, $device->publicKeyBytes())),
            'issued_at' => now()->getTimestamp(),
            'expires_at' => $license->expires_at->getTimestamp(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $key = SigningKey::load();

        return [
            'v' => 1,
            'payload' => base64_encode($payload),
            'key_id' => $key->keyId,
            'sig' => base64_encode($key->sign($payload)),
        ];
    }
}
