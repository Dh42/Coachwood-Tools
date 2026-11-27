<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Services\AspTracker\AspReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class AspTrackerController extends Controller
{
    public function index(Request $request, AspReportService $service): View
    {
        $filters = $this->filters($request);
        $groupBy = $request->query('group_by') === 'product' ? 'product' : 'variant';
        $store = $filters['store'];

        return view('tools.asp-tracker.index', [
            'filters' => $filters,
            'groupBy' => $groupBy,
            'storeName' => config("asp_tracker.stores.{$store}.name"),
            // Route names differ per store's tool (see routes/tools.php).
            'routeBase' => 'tools.'.config("asp_tracker.stores.{$store}.tool_slug"),
            'defaultViewLabel' => config("asp_tracker.stores.{$store}.default_exclusions.label"),
            'summary' => $service->summary($filters),
            'periods' => $service->periods($filters),
            'breakdown' => $service->breakdown($filters, $groupBy),
            'products' => $service->products($store),
            'variants' => $filters['product_id'] !== null ? $service->variants($store, $filters['product_id']) : [],
            'channels' => $service->channels($store),
            'ingest' => $service->ingestStatus($store),
        ]);
    }

    public function drilldown(Request $request, AspReportService $service): JsonResponse
    {
        $filters = $this->filters($request);
        $period = (string) $request->query('period');

        $valid = match ($filters['granularity']) {
            'month' => preg_match('/^\d{4}-\d{2}$/', $period),
            default => preg_match('/^\d{4}-\d{2}-\d{2}$/', $period),
        };
        if (! $valid) {
            return response()->json(['message' => 'Invalid period.'], 422);
        }

        return response()->json($service->drilldown($filters, $period));
    }

    public function exportCsv(Request $request, AspReportService $service): Response
    {
        $filters = $this->filters($request);
        $table = $request->query('table') === 'breakdown' ? 'breakdown' : 'periods';
        $groupBy = $request->query('group_by') === 'product' ? 'product' : 'variant';

        $metricHeader = ['Gross qty', 'Net qty', 'Net revenue', 'ASP', 'Product ASP', 'List ASP', 'Discount depth', 'Full-price share', 'Line-level discount', 'Order-level discount', 'Subscription discount'];
        $metrics = fn (array $r) => [
            $r['gross_qty'], $r['net_qty'], self::num($r['net_revenue']), self::num($r['asp']), self::num($r['product_asp']), self::num($r['list_asp']),
            self::num($r['discount_depth'], 4), self::num($r['full_price_share'], 4),
            self::num($r['line_discount']), self::num($r['order_discount']), self::num($r['selling_plan_discount']),
        ];

        if ($table === 'periods') {
            $rows = [['Period', ...$metricHeader, 'Free units', 'Top discounts']];
            foreach ($service->periods($filters) as $p) {
                $top = implode('; ', array_map(fn ($l) => $l['label'].' $'.number_format($l['amount'], 2, '.', ''), $p['top_labels']));
                $rows[] = [$p['period'], ...$metrics($p), $p['free_qty'], $top];
            }
        } else {
            $rows = [$groupBy === 'product' ? ['Product ID', 'Product', ...$metricHeader] : ['Variant ID', 'Product', 'Variant', 'SKU', ...$metricHeader]];
            foreach ($service->breakdown($filters, $groupBy) as $r) {
                $rows[] = $groupBy === 'product'
                    ? [$r['id'], $r['product_title'], ...$metrics($r)]
                    : [$r['id'], $r['product_title'], $r['variant_title'], $r['sku'], ...$metrics($r)];
            }
        }

        $csv = '';
        foreach ($rows as $row) {
            $csv .= implode(',', array_map(fn ($v) => self::cell($v), $row))."\n";
        }

        $name = "asp-{$table}-".strtolower($filters['store'])."-{$filters['from']}-to-{$filters['to']}.csv";

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
        ]);
    }

    private function filters(Request $request): array
    {
        // Each store's routes carry their store as a route default.
        return AspReportService::normalizeFilters($request->query(), $request->route('store'));
    }

    private static function num(?float $v, int $decimals = 2): string
    {
        return $v === null ? '' : number_format($v, $decimals, '.', '');
    }

    private static function cell(mixed $v): string
    {
        $v = (string) $v;

        return preg_match('/[",\n]/', $v) ? '"'.str_replace('"', '""', $v).'"' : $v;
    }
}
