{{-- A signed change with its data-raw value for the display multiplier. Sign and colour, never colour alone. --}}
@php $deltaClass = $value === null ? '' : ($value >= 0 ? 'delta-up' : 'delta-down'); @endphp
<span class="mult-signed {{ $deltaClass }} {{ $class ?? '' }}" data-raw="{{ $value === null ? 'null' : $value }}">{{ $value !== null ? ($value >= 0 ? '+' : '').number_format($value) : '—' }}</span>
