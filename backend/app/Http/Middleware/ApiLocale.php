<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** API language from Accept-Language (ar | en), else the token owner's preference, else Arabic. */
class ApiLocale
{
    public function handle(Request $request, Closure $next)
    {
        $locale = $request->headers->has('Accept-Language')
            ? $request->getPreferredLanguage(config('lugha.locales'))
            : ($request->user('sanctum')?->locale ?? config('lugha.default_locale'));
        app()->setLocale(in_array($locale, config('lugha.locales'), true) ? $locale : config('lugha.default_locale'));

        $response = $next($request);
        $response->headers->set('Content-Language', app()->getLocale());

        return $response;
    }
}
