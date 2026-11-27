<x-layouts.app title="New Tool" breadcrumb="Admin · Tools">
    <x-card>
        <form method="post" action="{{ route('admin.tools.store') }}" class="form-stack">
            @csrf
            @include('admin.tools._form')
            <div class="form-actions">
                <a href="{{ route('admin.tools.index') }}" class="btn">{{ __('Cancel') }}</a>
                <button type="submit" class="btn btn-primary">{{ __('Create Tool') }}</button>
            </div>
        </form>
    </x-card>
</x-layouts.app>
