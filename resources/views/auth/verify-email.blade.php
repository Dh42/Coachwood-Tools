<x-layouts.guest title="Verify your email">
    <h1 class="section-title">{{ __('Verify your email') }}</h1>
    <p class="muted text-sm">
        {{ __('Thanks for signing up! Before getting started, could you verify your email address by clicking on the link we just emailed to you? If you didn\'t receive the email, we will gladly send you another.') }}
    </p>

    <div class="form-actions">
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="btn">{{ __('Log Out') }}</button>
        </form>

        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <button type="submit" class="btn btn-primary">{{ __('Resend Verification Email') }}</button>
        </form>
    </div>
</x-layouts.guest>
