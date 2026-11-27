<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GmailConnection;
use App\Notifications\GmailConnectionTestNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\View\View;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Throwable;

/**
 * Admin > Mail settings: connect the Gmail account the app sends from.
 * Ported from the old app; the test email is fixed (found issue 19).
 */
class MailConnectionController extends Controller
{
    public function show(): View
    {
        return view('admin.mail.show', [
            'connection' => GmailConnection::current(),
        ]);
    }

    public function connect(): RedirectResponse
    {
        return Socialite::driver('google')
            ->redirectUrl(route('admin.mail.callback'))
            ->scopes(['https://www.googleapis.com/auth/gmail.send'])
            ->with(['access_type' => 'offline', 'prompt' => 'consent'])
            ->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        try {
            $googleUser = Socialite::driver('google')
                ->redirectUrl(route('admin.mail.callback'))
                ->user();
        } catch (InvalidStateException) {
            return redirect()->route('admin.mail.show')->withErrors([
                'mail' => 'Connecting the Gmail account failed. Please try again.',
            ]);
        }

        if (! $googleUser->refreshToken) {
            return redirect()->route('admin.mail.show')->withErrors([
                'mail' => 'Google did not return a refresh token. Disconnect this app under your Google Account\'s '.
                    'third-party access settings and try connecting again.',
            ]);
        }

        GmailConnection::query()->delete();

        GmailConnection::create([
            'email' => $googleUser->getEmail(),
            'refresh_token' => $googleUser->refreshToken,
            'connected_by' => $request->user()->id,
        ]);

        Cache::forget('gmail_api_access_token');

        activity('admin')
            ->causedBy($request->user())
            ->withProperties(['email' => $googleUser->getEmail()])
            ->log('Gmail sending account connected');

        return redirect()->route('admin.mail.show')->with('status', 'Gmail account connected.');
    }

    /**
     * A failure is shown on the page instead of a 500 (found issue 19), so
     * the admin sees what Google said.
     */
    public function test(Request $request): RedirectResponse
    {
        try {
            Notification::send($request->user(), new GmailConnectionTestNotification);
        } catch (Throwable $e) {
            Log::error('Test email failed to send', ['message' => $e->getMessage()]);

            return redirect()->route('admin.mail.show')->with('error', 'The test email failed to send: '.$e->getMessage());
        }

        return redirect()->route('admin.mail.show')->with('status', 'Test email sent — check the inbox.');
    }
}
