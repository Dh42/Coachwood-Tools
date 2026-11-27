{{--
    The page shell: sidebar and main column, from the Claude Design canvas
    "Coachwood Tools Redesign" (Home artboard).

    <x-layouts.app title="PO Checker" breadcrumb="Amazon · Purchase orders">
        <x-slot:actions><a class="btn btn-primary" href="...">Upload</a></x-slot:actions>
        <x-slot:subtitle>Check a POItemExport against Cin7 stock.</x-slot:subtitle>
        ...page content...
    </x-layouts.app>
--}}
@props(['title' => null, 'breadcrumb' => null, 'heading' => null])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="{{ \App\View\Preferences::theme() }}" data-font-size="{{ \App\View\Preferences::fontSize() }}">
<head>
    <x-layouts.head :title="$title" />
</head>
<body>
<a class="sr-only" href="#main">Skip to content</a>
<div class="shell">
    @include('components.layouts.sidebar')

    <main id="main" class="main" tabindex="-1">
        <div class="content">
            @if ($title || isset($actions))
                <x-page-header :title="$heading ?? $title" :breadcrumb="$breadcrumb" :subtitle="$subtitle ?? null" :actions="$actions ?? null" />
            @endif

            {{ $slot }}
        </div>
    </main>
</div>
<x-toasts />
</body>
</html>
