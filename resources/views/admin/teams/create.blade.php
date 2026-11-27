<x-layouts.app title="New Team" breadcrumb="Admin · Teams">
    <x-card>
        <form method="post" action="{{ route('admin.teams.store') }}" class="form-stack">
            @csrf
            @include('admin.teams._form', ['team' => new \App\Models\Team])
            <div class="form-actions">
                <a href="{{ route('admin.teams.index') }}" class="btn">{{ __('Cancel') }}</a>
                <button type="submit" class="btn btn-primary">{{ __('Create Team') }}</button>
            </div>
        </form>
    </x-card>
</x-layouts.app>
