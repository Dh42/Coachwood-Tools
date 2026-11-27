<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\TeamAppSocialFollowSnapshot;
use App\Models\TeamAppSocialPostSnapshot;
use App\Services\TeamAppStats\Ga4Client;
use App\Services\TeamAppStats\SocialInsightsClient;
use App\Services\TeamAppStats\TeamAppStatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * BioSteel App Stats, ported from the old app. The page is rebuilt on the
 * new design; the six JSON endpoints are unchanged. Fix: found issue 20
 * (malformed date filters).
 */
class TeamAppStatsController extends Controller
{
    private const GRANULARITIES = ['raw', 'daily', 'weekly'];

    private const SOCIAL_PERIODS = ['WEEK', 'TWO_WEEKS', 'MONTH'];

    public function index(Request $request, TeamAppStatsService $service): View
    {
        // Malformed dates fall back to the defaults instead of a 500, and a
        // backwards range is swapped (found issue 20).
        $today = now()->toDateString();
        $to = $this->dateOrNull($request->string('to')->toString()) ?? $today;
        $from = $this->dateOrNull($request->string('from')->toString()) ?? Carbon::parse($to)->subDays(30)->toDateString();
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        $granularity = $request->string('g', 'daily')->toString();
        $granularity = in_array($granularity, self::GRANULARITIES, true) ? $granularity : 'daily';

        $fromDt = Carbon::parse($from)->startOfDay();
        $toDt = Carbon::parse($to)->endOfDay();

        $series = $service->series($fromDt, $toDt, $granularity);
        $breakdown = $service->categoryBreakdown($fromDt, $toDt);
        $ads = $service->adTotals($fromDt, $toDt);

        $first = $series[0] ?? null;
        $last = end($series) ?: null;
        $curUsers = $last['users'] ?? null;
        $curTeams = $last['teams'] ?? null;
        $netUsers = ($first && $last && $first['users'] !== null && $last['users'] !== null)
            ? $last['users'] - $first['users'] : null;
        $netTeams = ($first && $last && $first['teams'] !== null && $last['teams'] !== null)
            ? $last['teams'] - $first['teams'] : null;
        $days = max(1, $fromDt->diffInDays($toDt));
        $avgPerDay = $netUsers !== null ? $netUsers / $days : null;

        $chartData = [
            'labels' => array_column($series, 'label'),
            'users' => array_column($series, 'users'),
            'teams' => array_column($series, 'teams'),
            'usersGrowth' => array_column($series, 'usersGrowth'),
            'catLabels' => array_column($breakdown, 'category'),
            'catValues' => array_column($breakdown, 'teams'),
            'adLabels' => $ads['rows']->pluck('date')->map(fn ($d) => $d->toDateString())->all(),
            'adImpressions' => $ads['rows']->pluck('impressions')->map(fn ($v) => (int) $v)->all(),
            'adRequests' => $ads['rows']->pluck('ad_requests')->map(fn ($v) => (int) $v)->all(),
        ];

        return view('tools.team-app-stats.index', [
            'from' => $from,
            'to' => $to,
            'granularity' => $granularity,
            'today' => $today,
            'series' => $series,
            'breakdown' => $breakdown,
            'categoryCount' => count($breakdown),
            'ads' => $ads,
            'curUsers' => $curUsers,
            'curTeams' => $curTeams,
            'netUsers' => $netUsers,
            'netTeams' => $netTeams,
            'avgPerDay' => $avgPerDay,
            'days' => (int) $days,
            'chartData' => $chartData,
        ]);
    }

    public function ga4Data(Request $request, Ga4Client $client): JsonResponse
    {
        [$from, $to, $error] = $this->validatedRange($request);
        if ($error) {
            return $error;
        }

        $data = $client->dashboardData($from, $to);

        return $data === null
            ? response()->json(['error' => 'GA4 request failed'], 502)
            : response()->json($data);
    }

    public function ga4Realtime(Ga4Client $client): JsonResponse
    {
        $data = $client->realtime();

        return $data === null
            ? response()->json(['error' => 'GA4 request failed'], 502)
            : response()->json($data);
    }

    public function ga4SponsorData(Request $request, Ga4Client $client): JsonResponse
    {
        [$from, $to, $error] = $this->validatedRange($request);
        if ($error) {
            return $error;
        }

        $data = $client->sponsorData($from, $to);

        return $data === null
            ? response()->json(['error' => 'GA4 request failed'], 502)
            : response()->json($data);
    }

    public function socialTopPosts(Request $request, SocialInsightsClient $client): JsonResponse
    {
        $period = $this->validatedPeriod($request);
        if ($period === null) {
            return response()->json(['error' => 'period must be WEEK, TWO_WEEKS or MONTH'], 400);
        }

        $data = $client->topPosts($period);

        return $data === null
            ? response()->json(['error' => 'Social Insights request failed'], 502)
            : response()->json(['data' => $data]);
    }

    public function socialTopFollowed(Request $request, SocialInsightsClient $client): JsonResponse
    {
        $period = $this->validatedPeriod($request);
        if ($period === null) {
            return response()->json(['error' => 'period must be WEEK, TWO_WEEKS or MONTH'], 400);
        }

        $data = $client->topFollowed($period);

        return $data === null
            ? response()->json(['error' => 'Social Insights request failed'], 502)
            : response()->json(['data' => $data]);
    }

    /**
     * Every date either leaderboard has a stored (WEEK-period) snapshot
     * for, newest first, plus that one date's rows — defaults to the most
     * recent date when none is given. The two tables are unioned rather
     * than assuming they're always in lockstep: TeamAppStatsSnapshotSocialCommand
     * captures each independently, so a day where only one report
     * succeeded still shows up (with the other side empty) instead of
     * vanishing from the picker entirely.
     */
    public function socialHistory(Request $request): JsonResponse
    {
        $dates = TeamAppSocialPostSnapshot::query()->where('period', 'WEEK')->distinct()->pluck('captured_at')
            ->merge(TeamAppSocialFollowSnapshot::query()->where('period', 'WEEK')->distinct()->pluck('captured_at'))
            ->unique()
            ->sortDesc()
            ->values();

        $date = $request->string('date')->toString() ?: $dates->first();

        $posts = $date
            ? TeamAppSocialPostSnapshot::where('captured_at', $date)->where('period', 'WEEK')->orderBy('rank')->get()
            : collect();
        $followed = $date
            ? TeamAppSocialFollowSnapshot::where('captured_at', $date)->where('period', 'WEEK')->orderBy('rank')->get()
            : collect();

        return response()->json([
            'dates' => $dates->all(),
            'date' => $date,
            'posts' => $posts->values(),
            'followed' => $followed->values(),
        ]);
    }

    /** A real YYYY-MM-DD date, or null. */
    private function dateOrNull(string $value): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }
        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year) ? $value : null;
    }

    private function validatedPeriod(Request $request): ?string
    {
        $period = $request->string('period', 'WEEK')->toString();

        return in_array($period, self::SOCIAL_PERIODS, true) ? $period : null;
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?JsonResponse}
     */
    private function validatedRange(Request $request): array
    {
        $from = $request->string('from')->toString();
        $to = $request->string('to')->toString();
        $valid = fn (string $d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 && strtotime($d) !== false;

        if (! $valid($from) || ! $valid($to)) {
            return [null, null, response()->json(['error' => 'from and to must be YYYY-MM-DD'], 400)];
        }

        return [$from, $to, null];
    }
}
