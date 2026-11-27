{{-- <x-notice tone="warn" title="Partial data">Amazon has not sent the last two days yet.</x-notice> --}}
@props(['tone' => 'info', 'title' => null])
@php $icons = ['good' => 'check', 'bad' => 'alert', 'warn' => 'alert', 'near' => 'alert', 'info' => 'info']; @endphp
<div {{ $attributes->class('notice')->merge(['style' => '--tone: var(--'.$tone.')', 'role' => $tone === 'bad' ? 'alert' : 'status']) }}>
    <x-icon :name="$icons[$tone] ?? 'info'" />
    <div>
        @if ($title)<strong>{{ $title }}</strong> @endif{{ $slot }}
    </div>
</div>
