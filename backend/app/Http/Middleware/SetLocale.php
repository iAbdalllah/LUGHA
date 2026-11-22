<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/** Locale from the URL prefix (/ar, /en), else the session, else the user's preference, else Arabic. */
class SetLocale
{
    public function handle(Request $request, Closure $next)
    {
        $locales = config('lugha.locales');
        $fromUrl = $request->route('locale') ?? $request->segment(1);

        $locale = in_array($fromUrl, $locales, true) ? $fromUrl
            : ($request->session()->get('locale') ?? $request->user()?->locale ?? config('lugha.default_locale'));
        if (! in_array($locale, $locales, true)) {
            $locale = config('lugha.default_locale');
        }

        app()->setLocale($locale);
        $request->session()->put('locale', $locale);
        URL::defaults(['locale' => $locale]);

        if ($request->user() && $request->user()->locale !== $locale && in_array($fromUrl, $locales, true)) {
            $request->user()->forceFill(['locale' => $locale])->saveQuietly();
        }

        // Route parameters are positional for controllers; drop {locale} so actions receive only their own params.
        $request->route()?->forgetParameter('locale');

        return $next($request);
    }
}
