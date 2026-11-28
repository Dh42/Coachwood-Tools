{{-- The search results, returned on their own by the search request and dropped into #sponsorshipResults. --}}
@if ($error)
    <x-notice tone="bad" :title="__('Error:')">{{ $error }}</x-notice>
@endif

@if ($teams !== null)
    <section class="section">
        <div class="section-head">
            <h2 class="section-title">{{ __('Teams for :email (:count)', ['email' => $email, 'count' => count($teams)]) }}</h2>
        </div>

        @if (count($teams) === 0)
            <x-card><x-empty-state :title="__('No teams found.')" icon="search">{{ __('Check the spelling, or try the address the coach signed up to the BioSteel Teams app with.') }}</x-empty-state></x-card>
        @else
            <div class="grid gap-4 md:grid-cols-2">
                @foreach ($teams as $team)
                    <x-card :title="$team['team_name']">
                        @if ($team['possible_fraud'])
                            <x-slot:actions><x-status-badge tone="warn">{{ __('Possible fraud') }}</x-status-badge></x-slot:actions>
                        @endif

                        @if ($team['possible_fraud'])
                            <x-notice tone="warn">{{ __('Bottle count is well above the team\'s registered emails. Registered Emails: :count', ['count' => $team['email_count']]) }}</x-notice>
                        @endif

                        <dl class="scope-list scope-list-flush scope-list-compact">
                            @if (! empty($team['teamOwner']))
                                <dt>{{ __('Owner Name:') }}</dt><dd>{{ $team['teamOwner'] }}</dd>
                            @endif
                            @if (! empty($team['sport']))
                                <dt>{{ __('Sport:') }}</dt><dd>{{ \Illuminate\Support\Str::title(str_replace('_', ' ', strtolower($team['sport']))) }}</dd>
                            @endif
                            <dt>{{ __('Number of Bottles:') }}</dt><dd class="num">{{ $team['bottle_count'] }}</dd>
                            <dt>{{ __('Scheduled Events:') }}</dt><dd class="num">{{ $team['events_count'] !== null ? $team['events_count'] : __('—') }}</dd>
                            @if (! empty($team['division']))
                                <dt>{{ __('Division:') }}</dt><dd>{{ $team['division'] }}</dd>
                            @endif
                            @if (! empty($team['organization']))
                                <dt>{{ __('Organization:') }}</dt><dd>{{ $team['organization'] }}</dd>
                            @endif
                        </dl>

                        @if ($team['sent_order'])
                            <div class="flex flex-wrap items-center gap-2 text-sm">
                                <x-status-badge tone="good">{{ __('Sent') }}</x-status-badge>
                                <span class="muted">{{ $team['sent_order']->sent_at->format('Y-m-d H:i') }} UTC</span>
                                <span>{{ __('Order ID:') }}
                                    <a href="https://{{ config('services.shopify.store') }}/admin/draft_orders/{{ $team['sent_order']->shopify_order_id }}" target="_blank" rel="noopener">{{ $team['sent_order']->shopify_order_id }}</a>
                                </span>
                            </div>
                        @else
                            <button type="button" class="btn btn-primary btn-create-order"
                                    data-team-name="{{ $team['team_name'] }}"
                                    data-bottle-count="{{ $team['bottle_count'] }}"
                                    data-sport="{{ $team['sport'] }}">
                                {{ __('Create Order') }}
                            </button>
                        @endif

                        <details>
                            <summary class="muted text-sm">{{ __('View team response') }}</summary>
                            <pre class="snippet scroll-box">{{ json_encode($team, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                        </details>
                    </x-card>
                @endforeach
            </div>
        @endif
    </section>
@endif
