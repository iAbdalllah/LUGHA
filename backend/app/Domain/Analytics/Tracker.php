<?php

namespace App\Domain\Analytics;

use App\Models\AnalyticsEvent;
use App\Models\LocalizationPackage;
use Illuminate\Http\Request;

/**
 * First-party, cookie-less funnel events. Stores no IP address and no user agent; the visitor id is a hash of the
 * session id, the date and the app key, so it can't be joined across days or with other sites. Honors Do Not Track
 * and Global Privacy Control, and skips bots and staff.
 */
final class Tracker
{
    public const EVENTS = ['package_view', 'cart_add'];

    public function record(string $name, Request $request, ?LocalizationPackage $package = null): void
    {
        if (! in_array($name, self::EVENTS, true) || ! $this->allowed($request)) {
            return;
        }
        try {
            AnalyticsEvent::create([
                'name' => $name,
                'package_id' => $package?->id,
                'game_id' => $package?->game_id,
                'user_id' => $request->user()?->id,
                'visitor' => $this->visitor($request),
                'locale' => app()->getLocale(),
            ]);
        } catch (\Throwable $e) {
            report($e); // analytics must never break a page
        }
    }

    private function allowed(Request $request): bool
    {
        if ($request->header('DNT') === '1' || $request->header('Sec-GPC') === '1') {
            return false;
        }
        if ($request->user()?->is_staff) {
            return false;
        }

        return ! preg_match('/bot|crawl|spider|slurp|preview|headless|monitor/i', (string) $request->userAgent());
    }

    private function visitor(Request $request): string
    {
        $seed = $request->hasSession() ? $request->session()->getId() : ($request->user()?->id ?? $request->bearerToken() ?? 'anon');

        return substr(hash('sha256', $seed.'|'.now()->toDateString().'|'.config('app.key')), 0, 16);
    }
}
