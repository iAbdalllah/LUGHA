<?php

namespace App\Domain\Delivery;

use App\Models\Device;
use App\Models\Download;
use App\Models\Entitlement;
use App\Models\Installation;
use App\Models\LocalizationPackage;
use App\Models\PackageFile;
use App\Models\PackageVersion;
use App\Models\User;
use Illuminate\Support\Facades\URL;
use RuntimeException;

/** Which release a user may have, which file to send (delta or full), and the license that unlocks it. */
final class DeliveryService
{
    public function __construct(private LicenseIssuer $licenses) {}

    public function entitlementFor(User $user, LocalizationPackage $package): ?Entitlement
    {
        return Entitlement::where('user_id', $user->id)->where('package_id', $package->id)->where('status', 'active')->first();
    }

    /** Newest released, built version this entitlement covers. */
    public function latestFor(Entitlement $e): ?PackageVersion
    {
        return PackageVersion::where('package_id', $e->package_id)->where('build_status', 'released')->whereNotNull('content_key_encrypted')
            ->orderByDesc('released_at')->orderByDesc('id')->get()->first(fn ($v) => $e->covers($v));
    }

    /** Authorize a download for a device: returns the URL (5-minute signed link), file facts and the license. */
    public function authorize(User $user, Device $device, LocalizationPackage $package, ?string $fromVersion, ?string $ip): array
    {
        $this->assertDevice($user, $device);
        $e = $this->entitlementFor($user, $package) ?? throw new DeliveryException('not_owned', __('You do not own this package.'));
        $version = $this->latestFor($e) ?? throw new DeliveryException('no_release', __('No release of this package is available yet.'));

        $file = null;
        if ($fromVersion && $fromVersion !== $version->version) {
            $file = PackageFile::where('package_version_id', $version->id)->where('kind', 'delta')
                ->whereHas('baseVersion', fn ($q) => $q->where('version', $fromVersion))->first();
        }
        $file ??= $version->fullFile() ?? throw new RuntimeException('Release has no package file.');

        $download = Download::create(['user_id' => $user->id, 'device_id' => $device->id, 'package_file_id' => $file->id,
            'ip' => $ip, 'expires_at' => now()->addMinutes(10)]);

        return [
            'download_id' => $download->public_id,
            'url' => URL::temporarySignedRoute('api.downloads.file', now()->addMinutes(5), ['download' => $download->public_id]),
            'kind' => $file->kind,
            'version' => $version->version,
            'base_version' => $file->kind === 'delta' ? $fromVersion : null,
            'size' => $file->size_bytes,
            'sha256' => $file->sha256,
            'license' => $this->licenses->issue($e, $device, $version),
        ];
    }

    /** Fresh licenses for what the device reports as installed (extends the offline window). */
    public function refresh(User $user, Device $device): array
    {
        $this->assertDevice($user, $device);
        $out = [];
        foreach ($device->installations()->with('version.package')->get() as $inst) {
            $e = $this->entitlementFor($user, $inst->version->package);
            if ($e && $e->covers($inst->version)) {
                $out[$inst->version->package->slug] = $this->licenses->issue($e, $device, $inst->version);
            }
        }

        return $out;
    }

    /** Record installed versions; report what to update and what is no longer owned. */
    public function heartbeat(User $user, Device $device, array $installations, ?string $appVersion): array
    {
        $this->assertDevice($user, $device);
        $device->update(['last_seen_at' => now(), 'app_version' => $appVersion ?? $device->app_version]);

        $updates = [];
        $revoked = [];
        $reported = [];
        foreach ($installations as $row) {
            $package = LocalizationPackage::where('slug', $row['package'] ?? '')->first();
            $version = $package ? PackageVersion::where('package_id', $package->id)->where('version', $row['version'] ?? '')->first() : null;
            if (! $package || ! $version) {
                continue;
            }
            $reported[] = $package->id;
            Installation::updateOrCreate(['device_id' => $device->id, 'package_id' => $package->id], ['package_version_id' => $version->id, 'reported_at' => now()]);

            $e = $this->entitlementFor($user, $package);
            if (! $e) {
                $revoked[] = $package->slug;

                continue;
            }
            $latest = $this->latestFor($e);
            if ($latest && $latest->version !== $version->version && \version_compare($latest->version, $version->version, '>')) {
                $delta = PackageFile::where('package_version_id', $latest->id)->where('kind', 'delta')->where('base_version_id', $version->id)->first();
                $updates[] = ['package' => $package->slug, 'from' => $version->version, 'to' => $latest->version,
                    'size' => ($delta ?? $latest->fullFile())?->size_bytes, 'kind' => $delta ? 'delta' : 'full',
                    'release_notes' => $latest->release_notes];
            }
        }
        Installation::where('device_id', $device->id)->whereNotIn('package_id', $reported)->delete();

        return ['updates' => $updates, 'revoked' => $revoked, 'license_days' => (int) config('lugha.license_days')];
    }

    private function assertDevice(User $user, Device $device): void
    {
        if ($device->user_id !== $user->id || $device->status !== 'active') {
            throw new DeliveryException('device_inactive', __('This device is not active on your account.'));
        }
    }
}
