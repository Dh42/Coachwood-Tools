<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Services\Cdq\CdqReportService;
use Illuminate\Http\Response;
use Illuminate\View\View;

class CdqReportController extends Controller
{
    public function index(CdqReportService $service): View
    {
        return view('tools.cdq-report.index', [
            'scores' => $service->currentScores()->sortBy('total_score')->values(),
            'gradeDistribution' => $service->gradeDistribution(),
            'scoreHistogram' => $service->scoreHistogram(),
            'movement' => $service->weekOverWeekMovement(),
            'topDefects' => $service->topDefectsByPointsLost(),
            'parentRollup' => $service->parentImageRollup(),
        ]);
    }

    public function show(string $asin, CdqReportService $service): View
    {
        $data = $service->defectsForAsin($asin);
        abort_if($data === null, 404);

        return view('tools.cdq-report.show', $data);
    }

    public function exportCsv(CdqReportService $service): Response
    {
        $rows = $service->workQueue();

        // Every field through fputcsv (found issue 14: ASIN and SKU weren't quoted).
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['ASIN', 'SKU', 'Title', 'Grade', 'Total Score', 'Points Lost', 'Glance Views', 'Priority'], escape: '');
        foreach ($rows as $row) {
            fputcsv($out, [
                $row['asin'],
                $row['sku'],
                $row['title'],
                $row['grade'],
                $row['total_score'] ?? '',
                $row['points_lost'],
                $row['glance_views'] ?? '',
                $row['priority'],
            ], escape: '');
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="cdq-work-queue-'.now()->toDateString().'.csv"',
        ]);
    }
}
