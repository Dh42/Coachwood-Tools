{{-- <x-icon name="alert" size="sm|md|lg" label="..."/>: decorative unless a label is given. --}}
@props(['name', 'size' => 'md', 'label' => null])
<svg {{ $attributes->class(['icon', 'icon-'.$size => $size !== 'md']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
     @if ($label) role="img" aria-label="{{ $label }}" @else aria-hidden="true" @endif><path d="{{ \App\View\Icons::path($name) }}"></path></svg>
