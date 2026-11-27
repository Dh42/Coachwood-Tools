<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\GoogleWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;

/**
 * Google sign-in, the only way in (decision 2026-11-27). Ported from the old
 * app with these fixes (docs/found-issues.md, each with a failing test first):
 * - no configured domain now means nobody gets in, instead of anyone
 * - the Workspace `hd` claim is required; the old code fell back to the
 *   email's domain, which a consumer Google account can also have
 * - an email already linked to a different Google ID is refused instead of
 *   signed in to that account (found issue 8)
 * - new users are marked verified; the old code passed a non-fillable
 *   field that was dropped (found issue 27)
 * The activity-log lines come back with the activity log in step 3c.
 */
class GoogleAuthController extends Controller
{
    public function redirect(): RedirectResponse
    {
        $driver = Socialite::driver('google');
        $domains = GoogleWorkspace::allowedDomains();

        // Google's `hd` OAuth hint only ever accepts one exact domain or the
        // literal `*` (meaning "any Google Workspace account, not personal
        // Gmail") — it can't be handed a list. With more than one allowed
        // domain, `*` is the closest available hint; the real enforcement
        // is always the post-login isFromAllowedDomain() check below, which
        // does support the full list.
        if (count($domains) === 1) {
            $driver->with(['hd' => $domains[0]]);
        } elseif (count($domains) > 1) {
            $driver->with(['hd' => '*']);
        }

        return $driver->redirect();
    }

    public function callback(): RedirectResponse
    {
        try {
            /** @var SocialiteUser $googleUser */
            $googleUser = Socialite::driver('google')->user();
        } catch (InvalidStateException) {
            return redirect()->route('login')->withErrors([
                'email' => 'Google sign-in failed. Please try again.',
            ]);
        }

        $domains = GoogleWorkspace::allowedDomains();

        if ($domains === []) {
            return $this->refuse('Google sign-in is not set up yet. Contact an administrator.');
        }

        if (! $this->isFromAllowedDomain($googleUser)) {
            return $this->refuse(count($domains) === 1
                ? 'You must sign in with a '.$domains[0].' account.'
                : 'You must sign in with a Google account on one of these domains: '.implode(', ', $domains));
        }

        $user = User::where('google_id', $googleUser->getId())->first()
            ?? User::where('email', $googleUser->getEmail())->first();

        if ($user && $user->google_id !== null && $user->google_id !== $googleUser->getId()) {
            return $this->refuse('This email address is linked to a different Google account. Contact an administrator.');
        }

        if (! $user) {
            $user = User::create([
                'name' => $googleUser->getName() ?: $googleUser->getEmail(),
                'email' => $googleUser->getEmail(),
                'google_id' => $googleUser->getId(),
                'password' => null,
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();

            $user->assignRole(
                $this->isInitialAdmin($user) ? 'admin' : 'user'
            );
        } else {
            if ($user->isDisabled()) {
                return $this->refuse('This account has been disabled. Contact an administrator.');
            }

            if (! $user->google_id) {
                $user->forceFill(['google_id' => $googleUser->getId()])->save();
            }
        }

        Auth::login($user, remember: true);

        return redirect()->intended(route('dashboard'));
    }

    /**
     * Only Workspace accounts carry `hd`, the organisation's domain. The
     * email's own domain is not enough: a consumer Google account can be
     * registered on a company address.
     */
    protected function isFromAllowedDomain(SocialiteUser $googleUser): bool
    {
        $hostedDomain = $googleUser->getRaw()['hd'] ?? null;

        return is_string($hostedDomain) && $hostedDomain !== '' && GoogleWorkspace::isAllowedDomain($hostedDomain);
    }

    private function refuse(string $message): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['email' => $message]);
    }

    protected function isInitialAdmin(User $user): bool
    {
        $adminEmail = config('app.initial_admin_email');

        return $adminEmail && strcasecmp($user->email, $adminEmail) === 0;
    }
}
