@props(['title', 'breadcrumb' => null, 'subtitle' => null, 'actions' => null])
<header {{ $attributes->class('page-header') }}>
    <div>
        @if ($breadcrumb)<div class="label">{{ $breadcrumb }}</div>@endif
        <h1 class="page-title">{{ $title }}</h1>
        @if ($subtitle && trim($subtitle) !== '')
            <div class="muted">{{ $subtitle }}</div>
        @endif
    </div>
    @if ($actions && trim($actions) !== '')
        <div class="flex flex-wrap gap-2">{{ $actions }}</div>
    @endif
</header>
