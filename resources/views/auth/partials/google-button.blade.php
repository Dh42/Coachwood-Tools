{{-- Shown once Google sign-in is ported (step 3f) and its route exists. --}}
@if (Route::has('google.redirect'))
    <a href="{{ route('google.redirect') }}" class="btn w-full">
        {{ __('Sign in with Google') }}
    </a>
@endif
