{{--
    A page's "Tutorial" button and its steps (resources/js/tour.js).
    <x-tour-button id="tas-tour-btn" :steps="[['element' => '#tas-controls', 'intro' => 'Pick a date range...']]" />
--}}
@props(['id', 'steps'])
<button type="button" {{ $attributes->class('btn btn-sm') }} id="{{ $id }}" data-tour="{{ $id }}-steps">
    <x-icon name="info" size="sm" />{{ __('Tutorial') }}
</button>
<script type="application/json" id="{{ $id }}-steps">@json(array_values($steps))</script>
