<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Services\Feedback\FeedbackService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * US Product Reviews — Amazon's own review-topic classification (Customer
 * Feedback API) for every BioSteel ASIN across both the VC (.ca) and SC
 * (US) catalogs, queried against the US marketplace — the only one this
 * API supports that we actually operate in. See FeedbackService's header
 * for the full verification trail (Data Kiosk's CA path is blocked at the
 * marketplace level; this standalone API works immediately).
 */
class FeedbackController extends Controller
{
    public function __construct(protected FeedbackService $service) {}

    public function index(Request $request): View
    {
        $selectedAsin = $request->string('asin')->value();
        $hasAnyData = DB::table('feedback_raw_snapshots')->exists();

        if (! $hasAnyData) {
            return view('tools.feedback.index', ['hasAnyData' => false, 'selectedAsin' => $selectedAsin]);
        }

        if ($selectedAsin === '') {
            return view('tools.feedback.index', [
                'hasAnyData' => true, 'selectedAsin' => $selectedAsin,
                'rows' => $this->service->getLatestSummary(),
            ]);
        }

        $detail = $this->service->getAsinDetail($selectedAsin);
        if ($detail !== null) {
            foreach ($detail['topics'] as $topic) {
                $topic->implied_review_count = $this->service->impliedReviewCount(
                    $topic->number_of_mentions,
                    $topic->occurrence_percentage !== null ? (float) $topic->occurrence_percentage : null
                );
            }
        }

        $trendsByTopic = [];
        foreach ($this->service->getAsinTrends($selectedAsin) as $tr) {
            $trendsByTopic[$tr['polarity'].'|'.$tr['topic']] = $tr['points'];
        }

        return view('tools.feedback.index', [
            'hasAnyData' => true, 'selectedAsin' => $selectedAsin,
            'detail' => $detail, 'trendsByTopic' => $trendsByTopic,
        ]);
    }
}
