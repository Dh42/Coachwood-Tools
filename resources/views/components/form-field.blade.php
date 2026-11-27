{{--
    A labelled input with its validation message.
    <x-form-field name="email" label="Email" type="email" :value="old('email')" required autocomplete="username" />
    `bag` picks a named error bag (validateWithBag), e.g. bag="updatePassword".
--}}
@props(['name', 'label', 'type' => 'text', 'value' => null, 'bag' => 'default', 'id' => null, 'hint' => null])
@php
    $id ??= $name;
    $message = ($errors ?? new \Illuminate\Support\ViewErrorBag)->getBag($bag)->first($name);
    $describedBy = trim(($hint ? $id.'-hint ' : '').($message ? $id.'-error' : ''));
@endphp
<div class="field">
    <label for="{{ $id }}">{{ $label }}</label>
    <input {{ $attributes->class('input') }} id="{{ $id }}" name="{{ $name }}" type="{{ $type }}"
           @if (! is_null($value)) value="{{ $value }}" @endif
           @if ($message) aria-invalid="true" @endif
           @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif>
    @if ($hint)
        <p class="field-hint" id="{{ $id }}-hint">{{ $hint }}</p>
    @endif
    @if ($message)
        <p class="field-error" id="{{ $id }}-error"><x-icon name="alert" size="sm" />{{ $message }}</p>
    @endif
</div>
