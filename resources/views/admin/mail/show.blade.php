{{-- Admin > Mail settings, ported from the old app. --}}
<x-layouts.app title="Mail" breadcrumb="Admin · Settings">
    <x-slot:subtitle>Emails from this app (invitations, alerts, task failures) are sent through a connected Gmail account.</x-slot:subtitle>

    <x-card :title="__('Sending account')">
        @error('mail')
            <x-notice tone="bad">{{ $message }}</x-notice>
        @enderror

        @if ($connection)
            <p class="text-sm">
                {{ __('Connected as') }} <strong>{{ $connection->email }}</strong>
                @if ($connection->connectedBy)
                    <span class="muted">· {{ __('connected by :name, :when', ['name' => $connection->connectedBy->name, 'when' => $connection->created_at->diffForHumans()]) }}</span>
                @endif
            </p>
            <div class="flex flex-wrap gap-2">
                <form method="post" action="{{ route('admin.mail.test') }}">
                    @csrf
                    <button type="submit" class="btn btn-primary">{{ __('Send Test Email') }}</button>
                </form>
                <a href="{{ route('admin.mail.connect') }}" class="btn">{{ __('Reconnect') }}</a>
            </div>
            <p class="field-hint">{{ __('The test email goes to you. Reconnect if sending stops working, for example after the account\'s password or access was changed.') }}</p>
        @else
            <x-notice tone="warn" title="Not connected yet.">{{ __('Nothing can be emailed until an account is connected, invitations included.') }}</x-notice>
            <div>
                <a href="{{ route('admin.mail.connect') }}" class="btn btn-primary">
                    <img src="{{ asset('images/google-g.svg') }}" alt="" class="icon">
                    {{ __('Connect Gmail') }}
                </a>
            </div>
            <p class="field-hint">{{ __('When Google asks, sign in as the account emails should come from, for example tools@coachwoodgroup.com. The app can only send mail as it; it cannot read the mailbox.') }}</p>
        @endif
    </x-card>
</x-layouts.app>
