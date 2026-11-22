<?php

namespace App\Domain\Devices;

use App\Models\Device;
use App\Models\License;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

final class DeviceRegistry
{
    public static function limit(string $group): int
    {
        return (int) config("lugha.device_limits.$group", $group === 'tv' ? 2 : 3);
    }

    /**
     * Register (or re-register) a device. The same key on the same account is idempotent; limits are
     * enforced under a row lock so two parallel sign-ins can't both take the last slot.
     */
    public function register(User $user, array $data, ?int $tokenId): Device
    {
        $public = base64_decode($data['public_key'], true);
        if ($public === false || strlen($public) !== 32) {
            throw new DeviceException('invalid_public_key', __('The device key is invalid.'));
        }
        $fingerprint = hash('sha256', $public);

        return DB::transaction(function () use ($user, $data, $tokenId, $fingerprint) {
            User::whereKey($user->id)->lockForUpdate()->first();

            $existing = Device::where('key_fingerprint', $fingerprint)->first();
            if ($existing && $existing->user_id !== $user->id) {
                throw new DeviceException('key_in_use', __('This device key belongs to another account.'));
            }
            if ($existing && $existing->status === 'active') {
                $existing->update(array_filter(['name' => $data['name'], 'model' => $data['model'] ?? null, 'os_version' => $data['os_version'] ?? null,
                    'app_version' => $data['app_version'] ?? null, 'token_id' => $tokenId, 'last_seen_at' => now()]));

                return $existing;
            }

            $group = Device::limitGroup($data['kind']);
            $active = $user->devices()->where('status', 'active')->get()->filter(fn ($d) => Device::limitGroup($d->kind) === $group);
            if ($active->count() >= self::limit($group)) {
                throw new DeviceException('device_limit_reached', __('Device limit reached. Remove a device to add this one.'), ['devices' => $active->values()]);
            }

            $device = $existing ?? new Device(['user_id' => $user->id, 'key_fingerprint' => $fingerprint]);
            $device->fill([
                'kind' => $data['kind'], 'name' => $data['name'], 'model' => $data['model'] ?? null, 'os_version' => $data['os_version'] ?? null,
                'app_version' => $data['app_version'] ?? null, 'public_key' => $data['public_key'], 'status' => 'active',
                'token_id' => $tokenId, 'last_seen_at' => now(), 'removed_at' => null,
            ])->save();

            return $device;
        });
    }

    /** Remove a device: its token stops working and its licenses are revoked (enforced at next sync/expiry). */
    public function remove(Device $device): void
    {
        DB::transaction(function () use ($device) {
            $device->update(['status' => 'removed', 'removed_at' => now()]);
            if ($device->token_id) {
                PersonalAccessToken::whereKey($device->token_id)->delete();
            }
            License::where('device_id', $device->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $device->installations()->delete();
        });
    }
}
