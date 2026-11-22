<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class LibraryController extends Controller
{
    private function entitlements(Request $request)
    {
        return $request->user()->activeEntitlements()
            ->with(['package.game', 'package.categories', 'package.versions' => fn ($q) => $q->where('build_status', 'released')->latest('released_at')])
            ->orderByDesc('granted_at')->get();
    }

    public function index(Request $request)
    {
        return view('store.library', ['entitlements' => $this->entitlements($request)]);
    }

    public function downloads(Request $request)
    {
        return view('store.downloads', ['entitlements' => $this->entitlements($request)]);
    }
}
