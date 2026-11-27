<x-form-field name="name" :label="__('Name')" :value="old('name', $team->name)" required maxlength="255" />
<div class="field">
    <label for="description">{{ __('Description') }}</label>
    <textarea id="description" name="description" class="input textarea" rows="2">{{ old('description', $team->description) }}</textarea>
</div>
