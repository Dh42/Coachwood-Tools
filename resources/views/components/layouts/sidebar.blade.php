@php
    $theme = \App\View\Preferences::theme();
    $fontSize = \App\View\Preferences::fontSize();
    $user = auth()->user();
@endphp
<aside class="sidebar" aria-label="Sidebar">
    <div class="sidebar-top">
        <a href="{{ url('/') }}" class="sidebar-brand">
            <span class="brand-mark" aria-hidden="true">CW</span>
            <span class="brand-text">
                <span class="brand-name">{{ config('app.name', 'Coachwood Tools') }}</span>
                <span class="brand-sub">Coachwood Group</span>
            </span>
        </a>
        <button type="button" class="icon-btn sidebar-toggle" data-sidebar-toggle aria-expanded="false" aria-controls="sidebar-nav" aria-label="Menu">
            <x-icon name="menu" />
        </button>
    </div>

    <nav id="sidebar-nav" class="sidebar-nav" aria-label="Tools">
        @foreach (\App\View\Navigation::groups() as $group)
            <div class="nav-group">{{ $group['title'] }}</div>
            @foreach ($group['items'] as $item)
                <a href="{{ $item['href'] }}" class="nav-item" @if ($item['active']) aria-current="page" @endif>
                    <x-tool-icon :icon="$item['icon']" />
                    <span class="grow">{{ $item['label'] }}</span>
                    @isset($item['badge'])
                        <x-status-badge :tone="$item['badge']['tone']" :icon="false" :title="$item['badge']['label']">{{ $item['badge']['count'] }}</x-status-badge>
                    @endisset
                </a>
            @endforeach
        @endforeach
    </nav>

    <div class="sidebar-footer">
        <div class="section-head">
            <span class="label">Theme</span>
            <span class="label text-text" data-theme-name>{{ \App\View\Preferences::THEMES[$theme]['name'] }}</span>
        </div>
        <div class="swatches" role="group" aria-label="Theme">
            @foreach (\App\View\Preferences::THEMES as $id => $option)
                <button type="button" class="swatch" data-theme="{{ $id }}" data-theme-option="{{ $id }}" title="{{ $option['name'] }}"
                        aria-label="{{ $option['name'] }} theme" aria-pressed="{{ $id === $theme ? 'true' : 'false' }}"><span></span></button>
            @endforeach
        </div>

        <div class="section-head">
            <span class="label">Text size</span>
        </div>
        <div class="segmented" role="group" aria-label="Text size">
            @foreach (\App\View\Preferences::FONT_SIZES as $id => $label)
                <button type="button" data-font-size-option="{{ $id }}" aria-pressed="{{ $id === $fontSize ? 'true' : 'false' }}">{{ $label }}</button>
            @endforeach
        </div>

        @if ($user)
            <div class="user-chip">
                <a href="{{ route('profile.edit') }}" class="user-link" @if (request()->routeIs('profile.edit')) aria-current="page" @endif>
                    <span class="avatar" aria-hidden="true">{{ \Illuminate\Support\Str::of($user->name)->explode(' ')->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('') }}</span>
                    <span class="brand-text">
                        <span class="user-name">{{ $user->name }}</span>
                        <span class="brand-sub truncate">{{ $user->email }}</span>
                    </span>
                </a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="icon-btn" aria-label="Log out" title="Log out"><x-icon name="logout" /></button>
                </form>
            </div>
        @endif
    </div>
</aside>
