{{--
    BioSteel App Stats, ported from the old app onto the new design. The
    element IDs, data-raw attributes and mult-* classes are the old page's:
    resources/js/tools/team-app-stats.js and the ported tests rely on them.
    GA4 and Social Insights load live from the JSON endpoints; the snapshot
    and ad numbers are rendered here.
--}}
@php
    $tourSteps = [
        ['element' => '#tas-controls', 'intro' => __('Pick a date range and granularity, or use one of the quick-range links on the right.')],
        ['element' => '#tas-kpi-cards', 'intro' => __('Current totals and growth over the selected range, from the hourly BioSteel Teams app snapshot, plus active users for the range from Google Analytics.')],
        ['element' => '#tas-sport-section', 'intro' => __("Each sport's share of the total and its growth over the selected range.")],
        ['element' => '#tas-ga4-cards', 'intro' => __('Live Google Analytics data for the app itself, fetched fresh for the exact selected range every time this page loads.')],
        ['element' => '#tas-ga4-sponsor-cards', 'intro' => __('Traffic from the QR code on team-sponsorship packaging, tracked separately from the rest of the site.')],
        ['element' => '#tas-social-cards', 'intro' => __("Live top-posts/top-followed leaderboards from Social Insights — pick a period and it fetches fresh. A daily snapshot below lets you look back at a past day's leaderboard too.")],
    ];
    $quickRanges = [
        ['label' => __('24h'), 'from' => now()->subDay()->toDateString(), 'g' => 'raw'],
        ['label' => __('48h'), 'from' => now()->subDays(2)->toDateString(), 'g' => 'raw'],
        ['label' => __('7d'), 'from' => now()->subDays(7)->toDateString(), 'g' => 'daily'],
        ['label' => __('30d'), 'from' => now()->subDays(30)->toDateString(), 'g' => 'daily'],
        ['label' => __('90d'), 'from' => now()->subDays(90)->toDateString(), 'g' => 'daily'],
        ['label' => __('All'), 'from' => '2000-01-01', 'g' => 'weekly'],
    ];
    $granularities = ['raw' => __('Hourly'), 'daily' => __('Daily'), 'weekly' => __('Weekly')];
    $config = [
        'chart' => $chartData,
        'range' => ['from' => $from, 'to' => $to],
        'granularity' => $granularity,
        'routes' => [
            'ga4Data' => route('tools.team-app-stats.ga4-data'),
            'ga4Realtime' => route('tools.team-app-stats.ga4-realtime'),
            'ga4SponsorData' => route('tools.team-app-stats.ga4-sponsor-data'),
            'socialTopPosts' => route('tools.team-app-stats.social.top-posts'),
            'socialTopFollowed' => route('tools.team-app-stats.social.top-followed'),
            'socialHistory' => route('tools.team-app-stats.social.history'),
        ],
    ];
@endphp
<x-layouts.app title="BioSteel Teams App Stats" breadcrumb="BioSteel Teams">
    <x-slot:subtitle>{{ $from }} → {{ $to }} · {{ trans_choice(':count point|:count points', count($series)) }} · {{ $granularities[$granularity] }}</x-slot:subtitle>
    <x-slot:actions>
        <div class="flex flex-wrap items-center gap-2" id="tas-header-buttons">
            <x-tour-button id="tas-tour-btn" :steps="$tourSteps" />
            <button type="button" class="icon-btn" data-modal-open="tas-settings-modal" aria-label="{{ __('Settings') }}" title="{{ __('Settings') }}"><x-icon name="settings" /></button>
            <button type="button" class="icon-btn" id="tas-export-pdf-btn" aria-label="{{ __('Download PDF') }}" title="{{ __('Download PDF') }}"><x-icon name="file" id="tas-export-pdf-icon" /></button>
            <button type="button" class="icon-btn" id="tas-export-csv-btn" aria-label="{{ __('Download CSV') }}" title="{{ __('Download CSV') }}"><x-icon name="csv" /></button>
        </div>
    </x-slot:actions>

    <form method="GET" class="card mb-4" id="tas-controls">
        <div class="card-body">
            <div class="filter-bar">
                <label class="field">
                    <span>{{ __('From') }}</span>
                    <input type="date" id="tas-from" name="from" value="{{ $from }}" class="input">
                </label>
                <label class="field">
                    <span>{{ __('To') }}</span>
                    <input type="date" id="tas-to" name="to" value="{{ $to }}" class="input">
                </label>
                <fieldset class="field">
                    <span>{{ __('Granularity') }}</span>
                    <div class="segmented radio-segmented">
                        @foreach ($granularities as $val => $label)
                            <input type="radio" class="sr-only" name="g" id="tas-g-{{ $val }}" value="{{ $val }}" @checked($granularity === $val)>
                            <label for="tas-g-{{ $val }}">{{ $label }}</label>
                        @endforeach
                    </div>
                </fieldset>
                <button type="submit" class="btn btn-primary">{{ __('Apply') }}</button>
                <nav class="flex flex-wrap gap-1 ml-auto" id="tas-quick-ranges" aria-label="{{ __('Quick ranges') }}">
                    @foreach ($quickRanges as $qr)
                        @php $isOn = $from === $qr['from'] && $to === $today && $granularity === $qr['g']; @endphp
                        <a class="btn btn-sm {{ $isOn ? 'btn-primary' : '' }}" @if ($isOn) aria-current="true" @endif
                           href="{{ route('tools.team-app-stats.index', ['from' => $qr['from'], 'to' => $today, 'g' => $qr['g']]) }}">{{ $qr['label'] }}</a>
                    @endforeach
                </nav>
            </div>
        </div>
    </form>

    <x-modal id="tas-settings-modal" :title="__('Settings')">
        <label class="field">
            <span>{{ __('Software Version') }}</span>
            <input type="number" step="any" min="0" id="tas-multiplier-input" class="input" value="1">
        </label>
        <x-slot:footer>
            <button type="button" class="btn btn-primary" id="tas-multiplier-ok">{{ __('OK') }}</button>
        </x-slot:footer>
    </x-modal>

    @if (empty($series))
        <x-card>
            <x-empty-state :title="__('No snapshots in this range yet')" icon="phone">
                {{ __('Once the hourly snapshot cron runs, points will appear here.') }}
            </x-empty-state>
        </x-card>
    @else
        <x-stat-row id="tas-kpi-cards" class="mb-4">
            <div class="stat-tile">
                <div class="label">{{ __('Users now') }}</div>
                <div class="stat-value mult-int" id="tas-stat-users-now" data-raw="{{ $curUsers === null ? 'null' : $curUsers }}">{{ $curUsers !== null ? number_format($curUsers) : '—' }}</div>
            </div>
            <div class="stat-tile">
                <div class="label">{{ __('Teams now') }}</div>
                <div class="stat-value mult-int" id="tas-stat-teams-now" data-raw="{{ $curTeams === null ? 'null' : $curTeams }}">{{ $curTeams !== null ? number_format($curTeams) : '—' }}</div>
                <div class="stat-sub">{{ __(':count categories tracked', ['count' => $categoryCount]) }}</div>
            </div>
            <div class="stat-tile">
                <div class="label">{{ __('User growth · range') }}</div>
                <div class="stat-value">@include('tools.team-app-stats._delta', ['value' => $netUsers])</div>
                @if ($avgPerDay !== null)
                    <div class="stat-sub"><span class="mult-decimal1" data-raw="{{ $avgPerDay }}">{{ number_format($avgPerDay, 1) }}</span> {{ __('/day avg') }}</div>
                @endif
            </div>
            <div class="stat-tile">
                <div class="label">{{ __('Team growth · range') }}</div>
                <div class="stat-value">@include('tools.team-app-stats._delta', ['value' => $netTeams])</div>
                <div class="stat-sub">{{ __('over :days days', ['days' => $days]) }}</div>
            </div>
            <div class="stat-tile">
                <div class="label">{{ __('Active users · range') }}</div>
                <div class="stat-value" id="tas-kpi-ga4-users"><span class="skeleton" aria-label="{{ __('Loading') }}"></span></div>
                <div class="stat-sub" id="tas-kpi-ga4-users-sub"></div>
            </div>
        </x-stat-row>

        <div class="grid gap-4 lg:grid-cols-2 mb-4">
            <x-card :title="__('Users over time')"><div class="chart-box"><canvas id="tas-users-chart" role="img" aria-label="{{ __('Users over time') }}"></canvas></div></x-card>
            <x-card :title="__('Teams over time')"><div class="chart-box"><canvas id="tas-teams-chart" role="img" aria-label="{{ __('Teams over time') }}"></canvas></div></x-card>
        </div>

        <x-card :title="__('User growth per period')" class="mb-4">
            <div class="chart-box"><canvas id="tas-growth-chart" role="img" aria-label="{{ __('User growth per period; green bars are gains, red bars are losses') }}"></canvas></div>
        </x-card>

        <div class="grid gap-4 lg:grid-cols-2 mb-4" id="tas-sport-section">
            <x-card :title="__('Teams by sport · latest')">
                @if ($breakdown)
                    <div class="chart-box chart-box-lg"><canvas id="tas-sport-chart" role="img" aria-label="{{ __('Teams by sport') }}"></canvas></div>
                @else
                    <p class="muted text-sm">{{ __('No category data in range.') }}</p>
                @endif
            </x-card>
            <x-card :title="__('Sport breakdown')">
                <div class="table-wrap scroll-box">
                    <table class="table" id="tas-sport-table">
                        <thead><tr><th scope="col">{{ __('Sport') }}</th><th scope="col" class="num">{{ __('Teams') }}</th><th scope="col" class="num">{{ __('Share') }}</th><th scope="col" class="num">{{ __('Δ range') }}</th></tr></thead>
                        <tbody>
                            @forelse ($breakdown as $b)
                                <tr>
                                    <td>{{ $b['category'] }}</td>
                                    <td class="num mult-int" data-raw="{{ $b['teams'] }}">{{ number_format($b['teams']) }}</td>
                                    <td class="num">{{ $b['pct'] !== null ? number_format($b['pct'], 1).'%' : '—' }}</td>
                                    {{-- "new" has no raw number behind it to scale, same distinction the old page draws. --}}
                                    <td class="num">
                                        @if ($b['growth'] !== null)
                                            @include('tools.team-app-stats._delta', ['value' => $b['growth']])
                                        @else
                                            <x-status-badge tone="info" :icon="false">{{ __('new') }}</x-status-badge>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="muted">{{ __('No category data in range.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-card>
        </div>

        <x-card :title="__('Ad performance')" class="mb-4">
            @if ($ads['rows']->isNotEmpty())
                <div class="stat-row stat-row-plain">
                    <div class="stat-tile">
                        <div class="label">{{ __('Impressions · range') }}</div>
                        <div class="stat-value mult-int" id="tas-ad-impressions" data-raw="{{ $ads['totals']['impressions'] }}">{{ number_format($ads['totals']['impressions']) }}</div>
                        <div class="stat-sub"><span class="mult-int" id="tas-ad-requests" data-raw="{{ $ads['totals']['ad_requests'] }}">{{ number_format($ads['totals']['ad_requests']) }}</span> {{ __('requests') }}</div>
                    </div>
                    {{-- Fill rate, CTR and viewability are ratios: unchanged by the display multiplier. --}}
                    <div class="stat-tile">
                        <div class="label">{{ __('Fill rate') }}</div>
                        <div class="stat-value" id="tas-ad-fill-rate">{{ $ads['fillRate'] !== null ? number_format($ads['fillRate'], 1).'%' : '—' }}</div>
                        <div class="stat-sub">{{ __('impressions ÷ requests') }}</div>
                    </div>
                    <div class="stat-tile">
                        <div class="label">{{ __('Clicks & CTR') }}</div>
                        <div class="stat-value mult-int" id="tas-ad-clicks" data-raw="{{ $ads['totals']['clicks'] }}">{{ number_format($ads['totals']['clicks']) }}</div>
                        <div class="stat-sub"><span id="tas-ad-ctr">{{ $ads['ctr'] !== null ? number_format($ads['ctr'], 2).'% CTR' : '—' }}</span></div>
                    </div>
                    <div class="stat-tile">
                        <div class="label">{{ __('Viewability') }}</div>
                        <div class="stat-value" id="tas-ad-viewability">{{ $ads['viewability'] !== null ? number_format($ads['viewability'], 1).'%' : '—' }}</div>
                        <div class="stat-sub"><span class="mult-int" id="tas-ad-viewable" data-raw="{{ $ads['totals']['viewable'] }}">{{ number_format($ads['totals']['viewable']) }}</span> {{ __('viewable') }}</div>
                    </div>
                </div>
                <div class="chart-box"><canvas id="tas-ads-chart" role="img" aria-label="{{ __('Ad requests and impressions by day') }}"></canvas></div>
            @else
                <p class="muted text-sm">{{ __('No ad data in range yet. Once the ad-collection cron runs daily, numbers will appear here.') }}</p>
            @endif
        </x-card>

        <section class="section">
            <div class="section-head"><h2 class="section-title">{{ __('App analytics · Google Analytics') }}</h2></div>
            <x-stat-row id="tas-ga4-cards">
                <div class="stat-tile">
                    <div class="label">{{ __('Events · range') }}</div>
                    <div class="stat-value" id="tas-ga4-events"><span class="skeleton" aria-label="{{ __('Loading') }}"></span></div>
                    <div class="stat-sub" id="tas-ga4-events-sub"></div>
                </div>
                <div class="stat-tile">
                    <div class="label">{{ __('Platform split') }}</div>
                    <div class="stat-value text-lg" id="tas-ga4-platform"><span class="skeleton" aria-label="{{ __('Loading') }}"></span></div>
                    <div class="stat-sub" id="tas-ga4-platform-sub"></div>
                </div>
                <div class="stat-tile">
                    <div class="label">{{ __('Active right now') }} <x-status-badge tone="good" :icon="false">{{ __('Live') }}</x-status-badge></div>
                    <div class="stat-value" id="tas-ga4-realtime"><span class="skeleton" aria-label="{{ __('Loading') }}"></span></div>
                    <div class="stat-sub">{{ __('last ~30 min, GA4 Realtime') }}</div>
                </div>
            </x-stat-row>

            <x-notice tone="warn" id="tas-ga4-error" hidden>{{ __("Couldn't load Google Analytics data for this range.") }}</x-notice>

            <div id="tas-ga4-body" class="flex flex-col gap-4" hidden>
                <div class="grid gap-4 lg:grid-cols-2">
                    <x-card :title="__('Active users by age')"><div class="chart-box"><canvas id="tas-ga4-age-chart" role="img" aria-label="{{ __('Active users by age') }}"></canvas></div></x-card>
                    <x-card :title="__('Active users by gender')"><div class="chart-box"><canvas id="tas-ga4-gender-chart" role="img" aria-label="{{ __('Active users by gender') }}"></canvas></div></x-card>
                </div>
                <div class="grid gap-4 lg:grid-cols-2">
                    <x-card :title="__('Active users by country')"><div class="chart-box"><canvas id="tas-ga4-country-chart" role="img" aria-label="{{ __('Active users by country') }}"></canvas></div></x-card>
                    <x-card :title="__('Countries')">
                        <div class="table-wrap scroll-box">
                            <table class="table">
                                <thead><tr><th scope="col">{{ __('Country') }}</th><th scope="col" class="num">{{ __('Active users') }}</th></tr></thead>
                                <tbody id="tas-ga4-country-rows"></tbody>
                            </table>
                        </div>
                    </x-card>
                </div>
                <div class="grid gap-4 lg:grid-cols-2">
                    <x-card :title="__('Users by city · top 100')">
                        <div class="table-wrap scroll-box">
                            <table class="table">
                                <thead><tr><th scope="col">{{ __('City') }}</th><th scope="col" class="num">{{ __('Share of users') }}</th></tr></thead>
                                <tbody id="tas-ga4-city-share-rows"></tbody>
                            </table>
                        </div>
                    </x-card>
                    <x-card :title="__('Users by province / state · top 100')">
                        <div class="table-wrap scroll-box">
                            <table class="table">
                                <thead><tr><th scope="col">{{ __('Province / state') }}</th><th scope="col" class="num">{{ __('Share of users') }}</th></tr></thead>
                                <tbody id="tas-ga4-region-share-rows"></tbody>
                            </table>
                        </div>
                    </x-card>
                </div>
                <div class="grid gap-4 lg:grid-cols-2">
                    <x-card :title="__('Top interests')">
                        <div class="table-wrap scroll-box">
                            <table class="table">
                                <thead><tr><th scope="col">{{ __('Interest') }}</th><th scope="col" class="num">{{ __('Active users') }}</th></tr></thead>
                                <tbody id="tas-ga4-interest-rows"></tbody>
                            </table>
                        </div>
                    </x-card>
                </div>
            </div>
        </section>

        <section class="section">
            <div class="section-head"><h2 class="section-title">{{ __('QR code campaign · Sponsorship landing') }}</h2></div>
            <x-stat-row id="tas-ga4-sponsor-cards">
                @foreach (['users' => __('Active users · range'), 'sessions' => __('Sessions · range'), 'engaged' => __('Engaged sessions'), 'key-events' => __('Key events')] as $key => $label)
                    <div class="stat-tile">
                        <div class="label">{{ $label }}</div>
                        <div class="stat-value" id="tas-sponsor-{{ $key }}"><span class="skeleton" aria-label="{{ __('Loading') }}"></span></div>
                        <div class="stat-sub" id="tas-sponsor-{{ $key }}-sub"></div>
                    </div>
                @endforeach
            </x-stat-row>
            <x-notice tone="warn" id="tas-ga4-sponsor-error" hidden>{{ __("Couldn't load QR campaign data for this range.") }}</x-notice>
        </section>

        <section class="section">
            <div class="section-head"><h2 class="section-title">{{ __('Social media') }}</h2></div>
            <div class="card" id="tas-social-cards">
                <div class="card-body">
                    <div class="flex flex-wrap items-center gap-3">
                        <fieldset class="segmented radio-segmented" id="tas-social-period-group">
                            <legend class="sr-only">{{ __('Period') }}</legend>
                            @foreach (['WEEK' => __('Week'), 'TWO_WEEKS' => __('Two Weeks'), 'MONTH' => __('Month')] as $val => $label)
                                <input type="radio" class="sr-only" name="social-period" id="tas-social-period-{{ $val }}" value="{{ $val }}" @checked($val === 'WEEK')>
                                <label for="tas-social-period-{{ $val }}">{{ $label }}</label>
                            @endforeach
                        </fieldset>
                        <span class="muted text-sm">{{ __('Live — fetched fresh for the selected period every time this loads') }}</span>
                    </div>
                </div>
            </div>

            <div class="grid gap-4 lg:grid-cols-5">
                <x-card :title="__('Top interacted posts')" class="lg:col-span-3">
                    <div id="tas-social-posts-loading" aria-busy="true"><span class="skeleton"></span></div>
                    <x-notice tone="warn" id="tas-social-posts-error" hidden>{{ __("Couldn't load top posts for this period.") }}</x-notice>
                    <p class="muted text-sm" id="tas-social-posts-empty" hidden>{{ __('No interacted posts in this period.') }}</p>
                    <div class="table-wrap scroll-box" id="tas-social-posts-table" hidden>
                        <table class="table">
                            <thead><tr><th scope="col">#</th><th scope="col">{{ __('Author') }}</th><th scope="col">{{ __('Type') }}</th><th scope="col" class="num">{{ __('Reactions') }}</th><th scope="col" class="num">{{ __('Comments') }}</th><th scope="col" class="num">{{ __('Interactions') }}</th></tr></thead>
                            <tbody id="tas-social-posts-rows"></tbody>
                        </table>
                    </div>
                </x-card>
                <x-card :title="__('Top followed accounts')" class="lg:col-span-2">
                    <div id="tas-social-followed-loading" aria-busy="true"><span class="skeleton"></span></div>
                    <x-notice tone="warn" id="tas-social-followed-error" hidden>{{ __("Couldn't load top followed accounts for this period.") }}</x-notice>
                    <p class="muted text-sm" id="tas-social-followed-empty" hidden>{{ __('No new followers in this period.') }}</p>
                    <div class="table-wrap scroll-box" id="tas-social-followed-table" hidden>
                        <table class="table">
                            <thead><tr><th scope="col">#</th><th scope="col">{{ __('Account') }}</th><th scope="col" class="num">{{ __('New') }}</th><th scope="col" class="num">{{ __('Total') }}</th></tr></thead>
                            <tbody id="tas-social-followed-rows"></tbody>
                        </table>
                    </div>
                </x-card>
            </div>

            <x-card :title="__('Leaderboard history')" id="tas-social-history-card">
                <x-slot:actions>
                    <label class="sr-only" for="tas-social-history-date">{{ __('Captured on') }}</label>
                    <select class="select" id="tas-social-history-date" hidden></select>
                </x-slot:actions>
                <p class="muted text-sm" id="tas-social-history-empty">{{ __('No stored leaderboard history yet — captured once daily (WEEK period). Check back tomorrow.') }}</p>
                <div class="grid gap-4 lg:grid-cols-5" id="tas-social-history-body" hidden>
                    <div class="table-wrap scroll-box lg:col-span-3">
                        <table class="table">
                            <thead><tr><th scope="col">#</th><th scope="col">{{ __('Author') }}</th><th scope="col">{{ __('Type') }}</th><th scope="col" class="num">{{ __('Reactions') }}</th><th scope="col" class="num">{{ __('Comments') }}</th><th scope="col" class="num">{{ __('Interactions') }}</th></tr></thead>
                            <tbody id="tas-social-history-posts-rows"></tbody>
                        </table>
                    </div>
                    <div class="table-wrap scroll-box lg:col-span-2">
                        <table class="table">
                            <thead><tr><th scope="col">#</th><th scope="col">{{ __('Account') }}</th><th scope="col" class="num">{{ __('New') }}</th><th scope="col" class="num">{{ __('Total') }}</th></tr></thead>
                            <tbody id="tas-social-history-followed-rows"></tbody>
                        </table>
                    </div>
                </div>
            </x-card>
        </section>

        <x-card :title="__('Data')">
            <x-data-table caption="Snapshot data" csv="team-app-stats-data.csv">
                <thead>
                    <tr>
                        <x-th sort>{{ __('When') }}</x-th>
                        <x-th sort type="number">{{ __('Users') }}</x-th>
                        <x-th sort type="number">{{ __('Δ Users') }}</x-th>
                        <x-th sort type="number">{{ __('Teams') }}</x-th>
                        <x-th sort type="number">{{ __('Δ Teams') }}</x-th>
                    </tr>
                </thead>
                <tbody>
                    @foreach (array_reverse($series) as $r)
                        <tr>
                            <td>{{ $r['label'] }}</td>
                            <td class="num mult-int" data-raw="{{ $r['users'] === null ? 'null' : $r['users'] }}">{{ $r['users'] !== null ? number_format($r['users']) : '—' }}</td>
                            <td class="num">@include('tools.team-app-stats._delta', ['value' => $r['usersGrowth']])</td>
                            <td class="num mult-int" data-raw="{{ $r['teams'] === null ? 'null' : $r['teams'] }}">{{ $r['teams'] !== null ? number_format($r['teams']) : '—' }}</td>
                            <td class="num">@include('tools.team-app-stats._delta', ['value' => $r['teamsGrowth']])</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-data-table>
        </x-card>
    @endif

    <script type="application/json" id="tas-config">@json($config)</script>
    @vite('resources/js/tools/team-app-stats.js')
</x-layouts.app>
