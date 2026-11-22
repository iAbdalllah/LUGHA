<?php

namespace App\Http\Controllers\Store;

use App\Domain\Devices\DeviceCodeFlow;
use App\Domain\Devices\DeviceRegistry;
use App\Http\Controllers\Controller;
use App\Models\Device;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    public function index(Request $request)
    {
        return view('store.devices', [
            'devices' => $request->user()->devices()->where('status', 'active')->latest('last_seen_at')->get(),
            'limits' => ['app' => DeviceRegistry::limit('app'), 'tv' => DeviceRegistry::limit('tv')],
        ]);
    }

    public function destroy(Request $request, Device $device, DeviceRegistry $registry)
    {
        abort_unless($device->user_id === $request->user()->id, 404);
        $registry->remove($device);

        return back()->with('status', __(':name was removed. Its packages stop working when its offline window ends.', ['name' => $device->name]));
    }

    public function activate(Request $request, DeviceCodeFlow $flow)
    {
        $code = $request->query('code');

        return view('store.activate', ['code' => $code, 'pending' => $code ? $flow->findPending($code) : null]);
    }

    public function decide(Request $request, DeviceCodeFlow $flow)
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:12'], 'decision' => ['required', 'in:approve,deny']]);
        $pending = $flow->findPending($data['code']);
        if (! $pending) {
            return back()->withErrors(['code' => __('This code is invalid or has expired. Check the code on your device.')])->withInput();
        }
        $flow->decide($pending, $request->user(), $data['decision'] === 'approve');

        return redirect()->route('activate')->with('status', $data['decision'] === 'approve'
            ? __(':name is now signed in to your account.', ['name' => $pending->client_name])
            : __('Sign-in request declined.'));
    }
}
