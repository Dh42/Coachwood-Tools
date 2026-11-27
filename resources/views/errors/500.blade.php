{{--
    Ported from the old app's errors/500.blade.php, wording unchanged. Uses
    the guest layout so it renders with no session or user. Laravel only
    shows it when APP_DEBUG is off.
--}}
<x-layouts.guest title="Something went wrong">
    <div class="flex flex-col items-center gap-2 text-center">
        <x-icon name="alert" class="text-warn" size="lg" />
        <h1 class="section-title">{{ __('Something went wrong') }}</h1>
        <p class="muted text-sm">
            {{ __("This wasn't caused by anything you did — an unexpected error stopped the page from loading. It hasn't been reported automatically, so please pass the details below along.") }}
        </p>
    </div>

    <div class="notice" style="--tone: var(--muted)">
        <div>
            <div class="font-semibold text-sm">{{ __('To get this fixed quickly, please include:') }}</div>
            <ol class="text-sm list-decimal pl-5">
                <li>{{ __('What you were doing, step by step — e.g. "uploading a PO file, then clicking Re-check on SKU 001-700.01"') }}</li>
                <li>{{ __('Any file(s) involved — attach the actual file, not just its name') }}</li>
                <li>{{ __('A screenshot of this screen') }}</li>
                <li>{{ __('The page and time below') }}</li>
            </ol>
        </div>
    </div>

    <label class="field">
        <span>{{ __('Page') }}</span>
        <input type="text" class="input" id="error-report-url" value="{{ url()->full() }}" readonly>
    </label>
    <label class="field">
        <span>{{ __('Time') }}</span>
        <input type="text" class="input" id="error-report-time" value="{{ now()->toDayDateTimeString() }}" readonly>
    </label>

    <div class="flex gap-2">
        <button type="button" class="btn btn-sm" id="copy-error-report-btn">{{ __('Copy page + time') }}</button>
        <a href="{{ url('/') }}" class="btn btn-primary btn-sm ml-auto">{{ __('Back to Dashboard') }}</a>
    </div>

    {{-- Inline, not in app.js: if the error is in the asset build, this still works. --}}
    <script>
        document.getElementById('copy-error-report-btn')?.addEventListener('click', function () {
            const btn = this;
            const text = 'Page: ' + document.getElementById('error-report-url').value
                + '\nTime: ' + document.getElementById('error-report-time').value;
            navigator.clipboard?.writeText(text);
            const original = btn.textContent;
            btn.textContent = '{{ __('Copied') }}';
            setTimeout(() => { btn.textContent = original; }, 1500);
        });
    </script>
</x-layouts.guest>
