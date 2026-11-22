<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SetAdminLocale
{
    public function handle(Request $request, Closure $next)
    {
        $locale = $request->user()?->locale;
        app()->setLocale(in_array($locale, config('lugha.locales'), true) ? $locale : config('lugha.default_locale'));

        return $next($request);
    }
}
