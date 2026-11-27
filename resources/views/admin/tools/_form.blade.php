@php
    $iconNames = array_keys(\App\View\Icons::PATHS);
@endphp
<x-form-field name="name" :label="__('Name')" :value="old('name', $tool->name)" required maxlength="255"
              :hint="$tool->exists ? __('The slug and permission (tool.:slug) keep their names when you rename a tool.', ['slug' => $tool->slug]) : __('The slug and the permission tool.<slug> are made from the name.')" />

<div class="field">
    <label for="description">{{ __('Description') }}</label>
    <textarea id="description" name="description" class="input textarea" rows="3" maxlength="1000">{{ old('description', $tool->description) }}</textarea>
    @error('description')<p class="field-error"><x-icon name="alert" size="sm" />{{ $message }}</p>@enderror
</div>

<x-form-field name="group" :label="__('Group')" :value="old('group', $tool->group)" list="tool-groups" maxlength="255"
              :hint="__('The sidebar and launcher heading, for example Amazon · Pricing. Empty shows under Tools.')" />
<datalist id="tool-groups">
    @foreach ($groups as $group)
        <option value="{{ $group }}"></option>
    @endforeach
</datalist>

<x-form-field name="icon" :label="__('Icon')" :value="old('icon', $tool->icon)" list="tool-icons" maxlength="255"
              :hint="__('An icon name from the list, or an emoji.')" />
<datalist id="tool-icons">
    @foreach ($iconNames as $name)
        <option value="{{ $name }}"></option>
    @endforeach
</datalist>

<x-form-field name="url" :label="__('URL')" :value="old('url', $tool->url)" maxlength="255" placeholder="/tools/my-tool" />

<x-form-field name="sort_order" type="number" :label="__('Sort order')" :value="old('sort_order', $tool->sort_order)" min="0"
              :hint="__('Lower comes first. Dragging rows in the list sets this for you.')" />

<label class="check">
    <input type="hidden" name="active" value="0">
    <input type="checkbox" name="active" value="1" @checked(old('active', $tool->active))>
    <span>{{ __('Active') }} <span class="muted">— {{ __('inactive tools are hidden from everyone, admins included') }}</span></span>
</label>
