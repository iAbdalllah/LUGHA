<?php

namespace App\Domain\Devices;

use App\Models\DeviceCode;
use App\Models\User;
use Illuminate\Support\Str;

/** OAuth-style device authorization (RFC 8628) so TVs and desktop apps never ask for a password. */
final class DeviceCodeFlow
{
    public const INTERVAL = 5;

    public const TTL_MINUTES = 10;

    private const ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ'; // no 0/O/1/I

    /** @return array{device_code: string, record: DeviceCode} */
    public function start(string $clientName): array
    {
        $deviceCode = Str::random(48);
        do {
            $raw = '';
            for ($i = 0; $i < 6; $i++) {
                $raw .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $userCode = substr($raw, 0, 3).'-'.substr($raw, 3);
        } while (DeviceCode::where('user_code', $userCode)->exists());

        $record = DeviceCode::create([
            'device_code_hash' => hash('sha256', $deviceCode), 'user_code' => $userCode, 'client_name' => $clientName,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        return ['device_code' => $deviceCode, 'record' => $record];
    }

    public static function normalizeUserCode(string $input): string
    {
        $c = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $input));

        return strlen($c) === 6 ? substr($c, 0, 3).'-'.substr($c, 3) : $c;
    }

    public function findPending(string $userCode): ?DeviceCode
    {
        return DeviceCode::where('user_code', self::normalizeUserCode($userCode))->where('status', 'pending')
            ->where('expires_at', '>', now())->first();
    }

    public function decide(DeviceCode $code, User $user, bool $approve): void
    {
        $code->update(['status' => $approve ? 'approved' : 'denied', 'user_id' => $user->id]);
    }

    /**
     * Poll result: ['error' => authorization_pending|slow_down|expired_token|access_denied] or ['user' => User].
     */
    public function poll(string $deviceCode): array
    {
        $code = DeviceCode::where('device_code_hash', hash('sha256', $deviceCode))->first();
        if (! $code || $code->expires_at->isPast() || $code->status === 'used') {
            return ['error' => 'expired_token'];
        }
        if ($code->status === 'denied') {
            return ['error' => 'access_denied'];
        }
        if ($code->status === 'pending') {
            $tooSoon = $code->last_polled_at && $code->last_polled_at->gt(now()->subSeconds(self::INTERVAL - 1));
            $code->update(['last_polled_at' => now()]);

            return ['error' => $tooSoon ? 'slow_down' : 'authorization_pending'];
        }
        $code->update(['status' => 'used']);

        return ['user' => $code->user, 'client_name' => $code->client_name];
    }
}
