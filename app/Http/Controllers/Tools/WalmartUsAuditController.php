<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Services\WalmartUs\WalmartUsAuditReportService;
use Illuminate\View\View;

class WalmartUsAuditController extends Controller
{
    public function index(WalmartUsAuditReportService $service): View
    {
        return view('tools.walmart-us-listing-auditor.index', [
            'catalogScore' => $service->latestCatalogScore(),
            'scoreHistory' => $service->catalogScoreHistory(),
            'topIssues' => $service->topIssuesByOccurrence(),
            'audits' => $service->currentAudits(),
        ]);
    }

    public function show(string $sku, WalmartUsAuditReportService $service): View
    {
        $data = $service->auditForSku($sku);
        abort_if($data === null, 404);

        return view('tools.walmart-us-listing-auditor.show', $data);
    }
}
