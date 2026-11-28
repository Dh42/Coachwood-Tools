<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TeamSponsorshipActivity;
use App\Services\BioSteelTeams\BioSteelTeamsClient;
use App\Services\Klaviyo\KlaviyoClient;
use App\Services\Shopify\ShopifyClient;
use App\Services\TeamSponsorship\KlaviyoSponsorshipNotifier;
use App\Services\TeamSponsorship\TeamActivityClient;
use App\Support\CsvCell;
use App\Support\ExternalWrites;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TeamSponsorshipController extends Controller
{
    public function index(): View
    {
        [$inventory, $inventoryError] = $this->inventorySnapshot();

        return view('tools.team-sponsorship.index', [
            'writesEnabled' => ExternalWrites::allowed(),
            'inventory' => $inventory,
            'inventoryError' => $inventoryError,
            'teams' => null,
            'email' => null,
            'error' => null,
        ]);
    }

    /**
     * Returns just the results partial (not the full page) — called via
     * AJAX from the search form so searching never reloads the page.
     * Inventory is deliberately left out here: it doesn't depend on the
     * searched email, so it stays as rendered on the initial page load
     * instead of being re-fetched from Shopify on every search.
     *
     * Live scheduled-events count per team, at the user's explicit request
     * despite the real cost: resolving a team's BioSteel teamId requires
     * paging its entire ~4,300-team org-wide roster (the same scan the
     * daily-refreshed Stats page cache exists specifically to avoid doing
     * live). Scanned only ONCE per search (not once per team) — that same
     * roster page-through already independently powers sportTypesForEmail()
     * elsewhere, so building the sportType and teamId lookups from one pass
     * here avoids paying for two full scans in the same request.
     */
    public function search(Request $request): View
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $email = $validated['email'];
        $error = null;
        $teams = null;

        try {
            $client = new BioSteelTeamsClient;
            $rawTeams = $client->teamsForEmail($email);

            // Sport type + teamId/events are both genuinely ancillary to the
            // primary team list (bottles, owner, sport) that this whole page
            // exists to show — a transport-level failure here (a real risk:
            // ConnectionException doesn't extend RuntimeException, so it
            // wouldn't be caught by this method's own catch block below)
            // must never turn into a 500 for what's otherwise a perfectly
            // good search result. Degrades to no sport type / no events
            // count rather than aborting.
            [$sportTypes, $teamIds] = $this->teamMetaForEmail($client, $email);
            $userId = $this->safeFindUserId($client, $email);

            $activityClient = new TeamActivityClient($client);
            $teams = array_map(function (array $team) use ($email, $sportTypes, $teamIds, $userId, $activityClient) {
                $team = $this->decorateTeam($team, $email, $sportTypes);
                $team['events_count'] = $this->safeEventsCount($activityClient, $teamIds, $userId, $team['team_name']);

                return $team;
            }, $rawTeams);
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        } catch (ConnectionException) {
            // Found issue 32: a timeout used to be a 500.
            $error = __("Couldn't reach the BioSteel Teams API. Try again in a minute.");
        }

        return view('tools.team-sponsorship._results', [
            'teams' => $teams,
            'email' => $email,
            'error' => $error,
        ]);
    }

    public function stats(): View
    {
        $ordersByMonth = Order::orderBy('sent_at')
            ->pluck('sent_at')
            ->groupBy(fn ($date) => $date->format('Y-m'))
            ->map(fn ($month, $key) => [
                'label' => Carbon::createFromFormat('Y-m', $key)->format('M Y'),
                'count' => $month->count(),
            ])
            ->values();

        $recentOrders = Order::latest('sent_at')->paginate(20);

        // Batched once, not per row: the activity table is bounded by
        // distinct sponsored teams (far smaller than total order count), so
        // loading it in full and matching in PHP avoids both an N+1 query
        // per order row and a fragile cross-database LOWER() join (MySQL's
        // ci collation vs. SQLite's case-sensitive default in tests).
        // Attached directly onto each order here so the view never needs to
        // touch TeamSponsorshipActivity itself.
        $activityByTeam = $this->activityByTeam();
        $recentOrders->getCollection()->each(function (Order $order) use ($activityByTeam) {
            $order->activity = $activityByTeam->get(TeamSponsorshipActivity::lookupKey($order->email, $order->team_name));
        });

        // "Sponsorships with no events" — scanned over EVERY order (not
        // just this page's 20), matching how totalOrders/topRequesters
        // already scan the full table. "Sponsorships," not "teams," per
        // how it's meant to read — a team sponsored twice counts twice,
        // same as every other KPI tile on this page. The denominator is
        // orders whose team activity has actually been refreshed with a
        // real events_count: a team that's simply never been refreshed yet
        // isn't evidence of "no events," it's just missing data, so it's
        // excluded rather than silently counted as a false zero.
        $ordersWithKnownEvents = 0;
        $ordersWithNoEvents = 0;
        foreach (Order::select('email', 'team_name')->get() as $pair) {
            $activity = $activityByTeam->get(TeamSponsorshipActivity::lookupKey($pair->email, $pair->team_name));
            if ($activity && $activity->events_count !== null) {
                $ordersWithKnownEvents++;
                if ($activity->events_count === 0) {
                    $ordersWithNoEvents++;
                }
            }
        }
        $noEventsPct = $ordersWithKnownEvents > 0 ? $ordersWithNoEvents / $ordersWithKnownEvents * 100 : null;

        // Total marketing-reachable emails — per distinct TEAM, not per
        // order: a team sponsored twice has the same roster either way, and
        // double-counting it would inflate reach for a mailing list that
        // only ever sends to each address once.
        $teamsWithEmailsData = $activityByTeam->whereNotNull('emails_count');

        return view('tools.team-sponsorship.stats', [
            'totalOrders' => Order::count(),
            'ordersThisMonth' => Order::whereBetween('sent_at', [now()->startOfMonth(), now()->endOfMonth()])->count(),
            'ordersLast30Days' => Order::where('sent_at', '>=', now()->subDays(30))->count(),
            'uniqueRequesters' => Order::distinct('email')->count('email'),
            'ordersByMonth' => $ordersByMonth,
            'topRequesters' => Order::selectRaw('email, COUNT(*) as total')
                ->groupBy('email')
                ->orderByDesc('total')
                ->limit(10)
                ->get(),
            'recentOrders' => $recentOrders,
            'noEventsPct' => $noEventsPct,
            'ordersWithNoEvents' => $ordersWithNoEvents,
            'ordersWithKnownEvents' => $ordersWithKnownEvents,
            'emailsTotal' => (int) $teamsWithEmailsData->sum('emails_count'),
            'teamsWithEmailsData' => $teamsWithEmailsData->count(),
            'productsByMonth' => $this->productsByMonth(),
            'activityRefreshedAt' => TeamSponsorshipActivity::max('refreshed_at'),
        ]);
    }

    /**
     * Quantity shipped per product per month, for the products-by-month
     * chart. "Other" only ever appears for backfilled historical items
     * whose variant_id doesn't match a currently-configured product — every
     * go-forward order is generated from config('team_sponsorship.*), so it
     * can only ever produce one of the known labels.
     *
     * Also includes a monthly average per product over the most recent 3
     * months of data — always divided by 3 per the requested formula, but
     * `months_counted` is surfaced too so the view can flag it honestly
     * when fewer than 3 months of real data exist yet (rather than
     * silently understating the average).
     *
     * @return array{months: Collection<int, string>, products: Collection<string, Collection<int, int>>, monthly_averages: Collection<string, array{average: float, months_counted: int}>}
     */
    protected function productsByMonth(): array
    {
        $knownLabels = collect(['bottles', 'sample_packs', 'team_towels', 'hockey_pucks'])
            ->map(fn ($key) => config("team_sponsorship.{$key}.label"));

        $items = OrderItem::with('order:id,sent_at')->get()
            ->each(function (OrderItem $item) use ($knownLabels) {
                $item->chart_label = $knownLabels->contains($item->product_label) ? $item->product_label : 'Other';
            });

        $monthGroups = $items->groupBy(fn (OrderItem $item) => $item->order->sent_at->format('Y-m'))->sortKeys();

        $productLabels = $knownLabels->values();
        if ($items->contains(fn (OrderItem $item) => $item->chart_label === 'Other')) {
            $productLabels->push('Other');
        }

        $products = $productLabels->mapWithKeys(fn ($label) => [
            $label => $monthGroups->map(fn ($monthItems) => $monthItems->where('chart_label', $label)->sum('quantity'))->values(),
        ]);

        return [
            'months' => $monthGroups->keys()->map(fn ($key) => Carbon::createFromFormat('Y-m', $key)->format('M Y'))->values(),
            'products' => $products,
            'monthly_averages' => $products->map(function (Collection $values) {
                $lastThree = $values->slice(-3);

                return [
                    'average' => round($lastThree->sum() / 3, 1),
                    'months_counted' => $lastThree->count(),
                ];
            }),
        ];
    }

    public function storeOrder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'teamName' => ['required', 'string'],
            'quantity' => ['required', 'integer', 'min:1'],
            'sport' => ['nullable', 'string'],
        ]);

        // Found issue 1: the page hides the button for a team that already
        // has an order, but nothing stopped a repeat request.
        $existing = Order::hasBeenSentFor($validated['email'], $validated['teamName']);
        if ($existing) {
            return response()->json(['success' => false, 'error' => __('This team already has a sponsorship order (:id, sent :date UTC). Nothing was sent.', [
                'id' => $existing->shopify_order_id, 'date' => $existing->sent_at->format('Y-m-d H:i'),
            ])]);
        }

        // Plan section 2, rule 3: with writes off, say what would have been
        // sent and send nothing (ShopifyClient refuses too).
        if (! ExternalWrites::allowed()) {
            return response()->json([
                'success' => false,
                'writesDisabled' => true,
                'error' => __('Orders are off in this app until cutover, so nothing was sent to Shopify or Klaviyo. This order would have contained:'),
                'lineItems' => (new ShopifyClient)->sponsorshipLineItems($validated['quantity'], $validated['sport'] ?? null),
            ]);
        }

        try {
            $result = (new ShopifyClient)->createSponsorshipDraftOrder(
                $validated['email'],
                $validated['teamName'],
                $validated['quantity'],
                $validated['sport'] ?? null
            );
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()]);
        }

        $order = Order::create([
            'email' => $validated['email'],
            'team_name' => Order::normalizeTeamName($validated['teamName']),
            'shopify_order_id' => $result['draft_order_id'],
            'sent_at' => now(),
        ]);

        foreach ($result['line_items'] as $lineItem) {
            $order->items()->create([
                'product_label' => $lineItem['label'],
                'quantity' => $lineItem['quantity'],
            ]);
        }

        activity('tool.team-sponsorship')
            ->causedBy($request->user())
            ->withProperties([
                'email' => $validated['email'],
                'team' => $validated['teamName'],
                'sport' => $validated['sport'] ?? null,
                'order_id' => $result['draft_order_id'],
            ])
            ->log('Sponsorship order created');

        // Fires after the order is already fully created — never blocks or
        // fails this response if Klaviyo is unreachable or misconfigured.
        (new KlaviyoSponsorshipNotifier(new KlaviyoClient('BS')))
            ->notifyClaimed($validated['email'], $validated['teamName'], $validated['sport'] ?? null, $result);

        return response()->json([
            'success' => true,
            'orderId' => $result['draft_order_id'],
            'orderName' => $result['order_name'],
            'adminUrl' => $result['admin_url'],
            'emailSent' => $result['email_sent'],
        ]);
    }

    /**
     * Sport type + teamId for every team this email administers, from one
     * pass over the org-wide roster (see search()'s own docblock for why
     * this isn't just two calls to BioSteelTeamsClient::sportTypesForEmail()
     * + a second method — that would scan the whole roster twice). Never
     * throws: a transport failure here means "no sport type, no events
     * count for anyone this search," not "search is broken."
     *
     * @return array{0: array<string, ?string>, 1: array<string, ?string>} [team name (normalized, lowercased) => sport type, team name => teamId]
     */
    protected function teamMetaForEmail(BioSteelTeamsClient $client, string $email): array
    {
        $sportTypes = [];
        $teamIds = [];

        try {
            $lowerEmail = trim(mb_strtolower($email));
            foreach ($client->allTeamAdminEmailRows() as $row) {
                if ($row['teamName'] === null || ! in_array($lowerEmail, $row['adminEmails'], true)) {
                    continue;
                }
                $key = mb_strtolower(Order::normalizeTeamName($row['teamName']));
                $sportTypes[$key] = $row['sportType'];
                $teamIds[$key] = $row['teamId'];
            }
        } catch (\Throwable) {
            // Degrade to "no sport type, no events for anyone" — see docblock.
        }

        return [$sportTypes, $teamIds];
    }

    /** Never throws — a transport failure here just means no events count, not a broken search. */
    protected function safeFindUserId(BioSteelTeamsClient $client, string $email): ?string
    {
        try {
            return $client->findUserIdByEmail($email);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, ?string>  $teamIds  team name (normalized, lowercased) => teamId
     */
    /**
     * Every sponsorship (not just the stats page's current 20), newest
     * first, with the same columns as the Recent Sponsorships grid. A
     * blank cell means no refreshed activity data, same as the grid's dash.
     */
    public function exportStats(): StreamedResponse
    {
        $activityByTeam = $this->activityByTeam();

        return response()->streamDownload(function () use ($activityByTeam) {
            $fh = fopen('php://output', 'w');

            fputcsv($fh, ['Date', 'Team', 'Email', 'Shopify Draft Order ID', 'Emails', 'Events', 'Games', 'Practices']);

            foreach (Order::latest('sent_at')->lazy() as $order) {
                $activity = $activityByTeam->get(TeamSponsorshipActivity::lookupKey($order->email, $order->team_name));

                // Found issue 33: team names and emails come from coaches.
                fputcsv($fh, [
                    $order->sent_at->format('Y-m-d H:i'),
                    CsvCell::text($order->team_name),
                    CsvCell::text($order->email),
                    $order->shopify_order_id,
                    $activity?->emails_count,
                    $activity?->events_count,
                    $activity?->games_count,
                    $activity?->practices_count,
                ]);
            }

            fclose($fh);
        }, 'team-sponsorships_'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=utf-8']);
    }

    /**
     * Keyed by TeamSponsorshipActivity::lookupKey(), matched in PHP — see
     * stats() for why this isn't a SQL join.
     *
     * @return Collection<string, TeamSponsorshipActivity>
     */
    protected function activityByTeam(): Collection
    {
        return TeamSponsorshipActivity::all()
            ->keyBy(fn (TeamSponsorshipActivity $a) => TeamSponsorshipActivity::lookupKey($a->email, $a->team_name));
    }

    protected function safeEventsCount(TeamActivityClient $activityClient, array $teamIds, ?string $userId, string $teamName): ?int
    {
        $teamId = $teamIds[mb_strtolower(Order::normalizeTeamName($teamName))] ?? null;

        // A null userId is fine — see TeamActivityClient's docblock (the
        // endpoint ignores it; plenty of owners can't be found by email).
        if ($teamId === null) {
            return null;
        }

        try {
            return $activityClient->eventsCount($userId, $teamId);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string, ?string> $sportTypes team name (normalized, lowercased) => sport type, from BioSteelTeamsClient::sportTypesForEmail() */
    protected function decorateTeam(array $team, string $email, array $sportTypes = []): array
    {
        $registered = (int) ($team['numberOfRegisteredPlayers'] ?? 0);
        $static = (int) ($team['numberOfStaticPlayers'] ?? 0);
        $staff = (int) ($team['numberOfStaff'] ?? 0);

        $emailCount = TeamSponsorshipActivity::computeEmailsCount($team);
        $bottleCount = $registered + $static + $staff;

        $team['bottle_count'] = $bottleCount;
        $team['email_count'] = $emailCount;
        $team['possible_fraud'] = $emailCount > 0 && $bottleCount > $emailCount * 1.2;

        $teamName = $team['teamName'] ?? 'Unnamed Team';
        $team['team_name'] = $teamName;
        $team['sent_order'] = Order::hasBeenSentFor($email, $teamName);
        $team['sport'] = $sportTypes[mb_strtolower(Order::normalizeTeamName($teamName))] ?? null;

        return $team;
    }

    /**
     * @return array{0: array, 1: ?string}
     */
    protected function inventorySnapshot(): array
    {
        $products = [
            config('team_sponsorship.bottles'),
            config('team_sponsorship.sample_packs'),
            config('team_sponsorship.team_towels'),
            config('team_sponsorship.hockey_pucks'),
        ];

        try {
            $client = new ShopifyClient;

            $info = array_map(function (array $product) use ($client) {
                $variant = $client->fetchVariant($product['variant_id']);

                return [
                    'label' => $product['label'],
                    'title' => $variant['title'] ?? $product['label'],
                    'sku' => $variant['sku'] ?? 'N/A',
                    'inventory' => isset($variant['inventory_quantity']) ? (int) $variant['inventory_quantity'] : 'N/A',
                ];
            }, $products);

            return [$info, null];
        } catch (RuntimeException $e) {
            return [[], $e->getMessage()];
        } catch (ConnectionException) {
            // Found issue 32: a timeout used to fail the whole page.
            return [[], __("Couldn't reach Shopify. Reload the page to try again.")];
        }
    }
}
