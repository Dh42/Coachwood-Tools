{{-- Pages without the sidebar: sign-in, errors. Must render with no session or user. --}}
@props(['title' => null])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="{{ \App\View\Preferences::theme() }}" data-font-size="{{ \App\View\Preferences::fontSize() }}">
<head>
    <x-layouts.head :title="$title" />
</head>
<body>
<main class="guest">
    <a href="{{ url('/') }}" class="sidebar-brand guest-brand">
        <span class="brand-mark" aria-hidden="true">CW</span>
        <span class="brand-name">{{ config('app.name', 'Coachwood Tools') }}</span>
    </a>
    <div class="card guest-card">
        <div class="card-body">
            {{ $slot }}
        </div>
    </div>
</main>
<x-toasts />
</body>
</html>
