<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureAccountActive
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user() && ! $request->user()->isActive()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();

            return redirect()->route('login')->withErrors(['email' => __('This account is not active. Please contact support.')]);
        }

        return $next($request);
    }
}
