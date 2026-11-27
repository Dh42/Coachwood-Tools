<x-layouts.app :title="'Edit '.$tool->name" breadcrumb="Admin · Tools">
    <x-card>
        <form method="post" action="{{ route('admin.tools.update', $tool) }}" class="form-stack">
            @csrf
            @method('PUT')
            @include('admin.tools._form')
            <div class="form-actions">
                <a href="{{ route('admin.tools.index') }}" class="btn">{{ __('Cancel') }}</a>
                <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
            </div>
        </form>
    </x-card>
</x-layouts.app>
