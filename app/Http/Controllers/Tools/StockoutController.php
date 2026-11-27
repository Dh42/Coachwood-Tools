<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * BioSteel Shopify Lost Sales (renamed from Stockout, decision D7). Ported
 * from the old app; the page is rebuilt on the new design. Fix: found issue
 * 20 (malformed date filters returned a 500; such a date is now ignored).
 */
class StockoutController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request): View
    {
        // lost_sales_estimates is append-only (each run keeps every prior
        // run's rows, per the brief's "recomputed, never edited in place"
        // rule) — the dashboard only ever shows the single latest batch,
        // identified by its shared computed_at timestamp, not the whole
        // accumulated history.
        $latestComputedAt = DB::table('lost_sales_estimates')->max('computed_at');

        // No params at all -> "All time" (this tool's original, already-seen
        // default) — same "presence, not value" convention as PO Stats'
        // picker, so an explicitly-cleared field is never confused with an
        // absent one.
        $hasRangeParams = $request->has('start') || $request->has('end');
        $start = $hasRangeParams ? $this->date($request->query('start')) : null;
        $end = $hasRangeParams ? $this->date($request->query('end')) : null;

        if ($start && $end && $start->gt($end)) {
            [$start, $end] = [$end, $start];
        }

        $estimates = collect();
        $summary = ['gross_revenue_lost' => 0, 'gross_units_lost' => 0, 'count' => 0];
        $confidenceCounts = ['normal' => 0, 'thin_fallback' => 0, 'insufficient_data' => 0];

        if ($latestComputedAt !== null) {
            $query = DB::table('lost_sales_estimates')->where('computed_at', $latestComputedAt);

            // Overlap filter, not "started within the range" — a stockout
            // that began before the window and is still ongoing (or ended
            // inside it) genuinely cost money during the window and must
            // still show up, just prorated (below) to only its portion of it.
            if ($end !== null) {
                $query->where('started_at', '<=', $end->copy()->endOfDay());
            }
            if ($start !== null) {
                $query->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>=', $start));
            }

            $now = now();
            $rows = $query->get()->map(function ($row) use ($start, $end, $now) {
                $intervalStart = Carbon::parse($row->started_at)->startOfDay();
                $intervalEnd = $row->ended_at !== null ? Carbon::parse($row->ended_at)->startOfDay() : $now->copy()->startOfDay();

                // No bound given on one/both sides -> that side of the
                // window is just this row's own interval edge, so an
                // unfiltered ("All time") request naturally reduces to the
                // full, un-prorated total with no special-case branch.
                $windowStart = $start ?? $intervalStart;
                $windowEnd = $end ?? $intervalEnd;

                $overlapStart = $intervalStart->max($windowStart);
                $overlapEnd = $intervalEnd->min($windowEnd);
                $overlapDays = $overlapStart->lte($overlapEnd) ? $overlapStart->diffInDays($overlapEnd) + 1 : 0;

                $row->window_days_out_of_stock = $overlapDays;
                $row->window_units_lost = round($row->baseline_units_per_day * $overlapDays, 2);
                $row->window_revenue_lost = round($row->baseline_revenue_per_day * $overlapDays, 2);

                return $row;
            })->filter(fn ($row) => $row->window_days_out_of_stock > 0)->values();

            $rows = $rows->sortByDesc('window_revenue_lost')->values();

            $summary = [
                'gross_revenue_lost' => $rows->sum('window_revenue_lost'),
                'gross_units_lost' => $rows->sum('window_units_lost'),
                'count' => $rows->count(),
            ];

            foreach ($rows->countBy('baseline_confidence') as $confidence => $count) {
                $confidenceCounts[$confidence] = $count;
            }

            $page = LengthAwarePaginator::resolveCurrentPage();
            $estimates = new LengthAwarePaginator(
                $rows->forPage($page, self::PER_PAGE),
                $rows->count(),
                self::PER_PAGE,
                $page,
                ['path' => $request->url(), 'query' => $request->query()]
            );
        }

        $historicalCoverage = [
            'inventory_from' => DB::table('inventory_history_daily')->min('day'),
            'inventory_to' => DB::table('inventory_history_daily')->max('day'),
            'sales_from' => DB::table('sales_history_daily')->min('day'),
            'sales_to' => DB::table('sales_history_daily')->max('day'),
        ];

        return view('tools.biosteel-shopify-lost-sales.index', [
            'estimates' => $estimates,
            'summary' => $summary,
            'confidenceCounts' => $confidenceCounts,
            'latestComputedAt' => $latestComputedAt,
            'historicalCoverage' => $historicalCoverage,
            'rangeStart' => $start?->toDateString(),
            'rangeEnd' => $end?->toDateString(),
        ]);
    }

    /** A real YYYY-MM-DD date, or null for anything else (found issue 20). */
    private function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return Carbon::parse($value)->startOfDay();
    }
}
