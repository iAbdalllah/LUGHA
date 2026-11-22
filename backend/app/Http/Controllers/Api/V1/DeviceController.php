<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Delivery\DeliveryService;
use App\Domain\Delivery\SigningKey;
use App\Domain\Devices\DeviceCodeFlow;
use App\Domain\Devices\DeviceRegistry;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Download;
use App\Models\LocalizationPackage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class DeviceController extends Controller
{
    public static function devicePayload(Device $d): array
    {
        return ['id' => $d->public_id, 'kind' => $d->kind, 'name' => $d->name, 'model' => $d->model, 'app_version' => $d->app_version,
            'fingerprint' => $d->key_fingerprint, 'last_seen_at' => $d->last_seen_at?->toIso8601String(), 'created_at' => $d->created_at->toIso8601String()];
    }

    /** Public keys apps pin to verify manifests and licenses. */
    public function trust()
    {
        $k = SigningKey::load();

        return response()->json(['keys' => [['key_id' => $k->keyId, 'alg' => 'ed25519', 'public' => $k->public]]]);
    }

    public function startDeviceCode(Request $request, DeviceCodeFlow $flow)
    {
        $data = $request->validate(['client_name' => ['required', 'string', 'max:120']]);
        $started = $flow->start($data['client_name']);

        return response()->json([
            'device_code' => $started['device_code'],
            'user_code' => $started['record']->user_code,
            'verification_url' => route('activate', ['locale' => app()->getLocale()]),
            'verification_url_complete' => route('activate', ['locale' => app()->getLocale(), 'code' => $started['record']->user_code]),
            'expires_in' => DeviceCodeFlow::TTL_MINUTES * 60,
            'interval' => DeviceCodeFlow::INTERVAL,
        ]);
    }

    public function pollDeviceCode(Request $request, DeviceCodeFlow $flow)
    {
        $data = $request->validate(['device_code' => ['required', 'string', 'max:100']]);
        $result = $flow->poll($data['device_code']);
        if (isset($result['error'])) {
            // RFC 8628 §3.5 error codes, as problem+json with status 400.
            return response()->json(['type' => 'https://lugha.app/errors/'.str_replace('_', '-', $result['error']), 'title' => 'Device authorization',
                'status' => 400, 'code' => $result['error']], 400, ['Content-Type' => 'application/problem+json']);
        }

        return response()->json([
            'token' => $result['user']->createToken($result['client_name'], [...AuthController::ABILITIES, 'devices:manage', 'download'])->plainTextToken,
            'user' => AuthController::userPayload($result['user']),
        ]);
    }

    public function index(Request $request)
    {
        return response()->json(['data' => $request->user()->devices()->where('status', 'active')->latest()->get()->map(fn ($d) => self::devicePayload($d)),
            'limits' => ['app' => DeviceRegistry::limit('app'), 'tv' => DeviceRegistry::limit('tv')]]);
    }

    public function store(Request $request, DeviceRegistry $registry)
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(Device::KINDS)], 'name' => ['required', 'string', 'max:120'], 'model' => ['nullable', 'string', 'max:120'],
            'os_version' => ['nullable', 'string', 'max:40'], 'app_version' => ['nullable', 'string', 'max:32'], 'public_key' => ['required', 'string', 'size:44'],
        ]);
        $device = $registry->register($request->user(), $data, $request->user()->currentAccessToken()?->id);

        return response()->json(['data' => self::devicePayload($device)], $device->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request, Device $device, DeviceRegistry $registry)
    {
        abort_unless($device->user_id === $request->user()->id, 404);
        $registry->remove($device);

        return response()->noContent();
    }

    public function heartbeat(Request $request, Device $device, DeliveryService $delivery)
    {
        abort_unless($device->user_id === $request->user()->id, 404);
        $data = $request->validate(['app_version' => ['nullable', 'string', 'max:32'], 'installations' => ['array', 'max:500'],
            'installations.*.package' => ['required', 'string'], 'installations.*.version' => ['required', 'string']]);

        return response()->json($delivery->heartbeat($request->user(), $device, $data['installations'] ?? [], $data['app_version'] ?? null));
    }

    public function refreshLicenses(Request $request, Device $device, DeliveryService $delivery)
    {
        abort_unless($device->user_id === $request->user()->id, 404);

        return response()->json(['licenses' => (object) $delivery->refresh($request->user(), $device)]);
    }

    public function authorizeDownload(Request $request, DeliveryService $delivery)
    {
        $data = $request->validate(['package' => ['required', 'string'], 'device' => ['required', 'string'], 'from_version' => ['nullable', 'string', 'max:32']]);
        $device = Device::where('public_id', $data['device'])->where('user_id', $request->user()->id)->firstOrFail();
        $package = LocalizationPackage::where('slug', $data['package'])->firstOrFail();

        return response()->json($delivery->authorize($request->user(), $device, $package, $data['from_version'] ?? null, $request->ip()), 201);
    }

    /** Served through a short-lived signed URL (swap for a CDN signed URL in production). */
    public function file(string $download)
    {
        $d = Download::where('public_id', $download)->with('file')->firstOrFail();
        abort_if($d->expires_at->isPast(), 410, 'Download link expired.');

        return Storage::disk($d->file->disk)->download($d->file->path, basename($d->file->path), ['Content-Type' => 'application/octet-stream']);
    }

    public function completeDownload(Request $request, string $download)
    {
        $d = Download::where('public_id', $download)->where('user_id', $request->user()->id)->with('file')->firstOrFail();
        $data = $request->validate(['sha256' => ['required', 'string', 'size:64']]);
        abort_unless(hash_equals($d->file->sha256, strtolower($data['sha256'])), 422, 'Checksum mismatch.');
        $d->update(['status' => 'completed', 'completed_at' => now()]);

        return response()->noContent();
    }
}
