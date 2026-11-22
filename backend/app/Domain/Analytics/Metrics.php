<?php

namespace App\Domain\Analytics;

use App\Enums\OrderStatus;
use App\Models\AnalyticsEvent;
use App\Models\Device;
use App\Models\Download;
use App\Models\LocalizationPackage;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TranslationFeedback;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Read-only numbers for the admin Analytics page. All money in minor units (SAR halalas). */
final class Metrics
{
    public readonly CarbonImmutable $from;

    public readonly CarbonImmutable $to;

    public function __construct(int $days = 30, ?CarbonImmutable $to = null)
    {
        $this->to = ($to ?? CarbonImmutable::now())->endOfDay();
        $this->from = $this->to->subDays(max(1, $days) - 1)->startOfDay();
    }

    public static function forFilter(?string $range): self
    {
        return new self(in_array((int) $range, [7, 30, 90, 365], true) ? (int) $range : 30);
    }

    private function paidOrders()
    {
        return Order::whereIn('status', [OrderStatus::Paid, OrderStatus::PartiallyRefunded, OrderStatus::Refunded])
            ->whereBetween('paid_at', [$this->from, $this->to]);
    }

    public function kpis(): array
    {
        $orders = $this->paidOrders();
        $gross = (int) (clone $orders)->sum('total_amount');
        $refunded = (int) (clone $orders)->sum('refunded_amount');
        $count = (clone $orders)->count();
        $buyers = (clone $orders)->distinct()->count('user_id');
        $visitors = AnalyticsEvent::where('name', 'package_view')->whereBetween('created_at', [$this->from, $this->to])->distinct()->count('visitor');

        // Repeat purchase: buyers in the window who have ≥ 2 paid orders ever — one input to the subscriptions decision.
        $repeat = $buyers ? Order::whereIn('status', [OrderStatus::Paid, OrderStatus::PartiallyRefunded])
            ->whereIn('user_id', (clone $orders)->select('user_id'))
            ->groupBy('user_id')->havingRaw('count(*) >= 2')->pluck('user_id')->count() : 0;

        return [
            'gross' => $gross,
            'refunded' => $refunded,
            'net' => $gross - $refunded,
            'orders' => $count,
            'aov' => $count ? intdiv($gross, $count) : 0,
            'buyers' => $buyers,
            'arppu' => $buyers ? intdiv($gross - $refunded, $buyers) : 0,
            'refund_rate' => $gross ? round(100 * $refunded / $gross, 1) : 0.0,
            'visitors' => $visitors,
            'conversion' => $visitors ? round(100 * $buyers / $visitors, 2) : 0.0,
            'repeat_rate' => $buyers ? round(100 * $repeat / $buyers, 1) : 0.0,
        ];
    }

    /** @return array{labels: string[], gross: float[], refunds: float[], orders: int[]} */
    public function revenueSeries(): array
    {
        $rows = $this->paidOrders()->get(['paid_at', 'total_amount', 'refunded_amount'])
            ->groupBy(fn ($o) => $o->paid_at->toDateString());
        $out = ['labels' => [], 'gross' => [], 'refunds' => [], 'orders' => []];
        for ($d = $this->from; $d <= $this->to; $d = $d->addDay()) {
            $day = $rows->get($d->toDateString(), collect());
            $out['labels'][] = $d->format('m-d');
            $out['gross'][] = round($day->sum('total_amount') / 100, 2);
            $out['refunds'][] = round($day->sum('refunded_amount') / 100, 2);
            $out['orders'][] = $day->count();
        }

        return $out;
    }

    /** Unique visitors/users per step. Views and cart adds come from events; checkouts and purchases from orders. */
    public function funnel(): array
    {
        $ev = fn (string $n) => AnalyticsEvent::where('name', $n)->whereBetween('created_at', [$this->from, $this->to])->distinct()->count('visitor');

        return [
            'package_view' => $ev('package_view'),
            'cart_add' => $ev('cart_add'),
            'checkout_start' => Order::whereBetween('created_at', [$this->from, $this->to])->distinct()->count('user_id'),
            'purchase' => $this->paidOrders()->distinct()->count('user_id'),
        ];
    }

    /** Per-package performance, best sellers first. */
    public function packages(int $limit = 20): Collection
    {
        $views = AnalyticsEvent::where('name', 'package_view')->whereBetween('created_at', [$this->from, $this->to])
            ->whereNotNull('package_id')->selectRaw('package_id, count(distinct visitor) as n')->groupBy('package_id')->pluck('n', 'package_id');
        $carts = AnalyticsEvent::where('name', 'cart_add')->whereBetween('created_at', [$this->from, $this->to])
            ->whereNotNull('package_id')->selectRaw('package_id, count(distinct visitor) as n')->groupBy('package_id')->pluck('n', 'package_id');
        $sales = OrderItem::query()->whereIn('order_id', $this->paidOrders()->select('id'))
            ->selectRaw('package_id, count(*) as units, sum(unit_amount - refunded_amount) as revenue')->groupBy('package_id')->get()->keyBy('package_id');
        $reports = TranslationFeedback::where('status', 'open')->whereNotNull('package_id')
            ->selectRaw('package_id, count(*) as n')->groupBy('package_id')->pluck('n', 'package_id');

        return LocalizationPackage::with('game')->get()->map(fn ($p) => [
            'package' => $p->name,
            'game' => $p->game?->title,
            'views' => (int) ($views[$p->id] ?? 0),
            'cart_adds' => (int) ($carts[$p->id] ?? 0),
            'units' => (int) ($sales[$p->id]->units ?? 0),
            'revenue' => (int) ($sales[$p->id]->revenue ?? 0),
            'conversion' => ($views[$p->id] ?? 0) ? round(100 * ($sales[$p->id]->units ?? 0) / $views[$p->id], 1) : null,
            'rating' => $p->rating_avg,
            'rating_count' => $p->rating_count,
            'qa' => $p->qa_score,
            'open_feedback' => (int) ($reports[$p->id] ?? 0),
        ])->sortByDesc(fn ($r) => [$r['revenue'], $r['views']])->take($limit)->values();
    }

    /** Devices seen in the window, by kind; downloads completed in the window. */
    public function usage(): array
    {
        return [
            'devices' => Device::where('status', 'active')->where('last_seen_at', '>=', $this->from)
                ->selectRaw('kind, count(*) as n')->groupBy('kind')->pluck('n', 'kind')->map(fn ($n) => (int) $n)->all(),
            'downloads' => Download::whereNotNull('completed_at')->whereBetween('completed_at', [$this->from, $this->to])->count(),
        ];
    }

    public function community(): array
    {
        $resolved = TranslationFeedback::whereBetween('resolved_at', [$this->from, $this->to]);
        $accepted = (clone $resolved)->where('status', 'accepted')->count();
        $total = (clone $resolved)->count();

        return [
            'open' => TranslationFeedback::where('status', 'open')->count(),
            'received' => TranslationFeedback::whereBetween('created_at', [$this->from, $this->to])->count(),
            'accepted' => $accepted,
            'acceptance_rate' => $total ? round(100 * $accepted / $total, 1) : 0.0,
            'median_hours_to_resolve' => $this->medianHours(),
            'top_contributors' => TranslationFeedback::where('status', 'accepted')->whereBetween('resolved_at', [$this->from, $this->to])
                ->select('user_id', DB::raw('count(*) as n'))->groupBy('user_id')->orderByDesc('n')->limit(5)
                ->with('user:id,name,contributor_display_name')->get()
                ->map(fn ($r) => ['name' => $r->user?->publicName() ?? '—', 'accepted' => (int) $r->n])->all(),
        ];
    }

    private function medianHours(): ?float
    {
        $h = TranslationFeedback::whereBetween('resolved_at', [$this->from, $this->to])->get(['created_at', 'resolved_at'])
            ->map(fn ($f) => $f->created_at->diffInMinutes($f->resolved_at) / 60)->sort()->values();
        if ($h->isEmpty()) {
            return null;
        }
        $mid = intdiv($h->count(), 2);

        return round($h->count() % 2 ? $h[$mid] : ($h[$mid - 1] + $h[$mid]) / 2, 1);
    }
}
