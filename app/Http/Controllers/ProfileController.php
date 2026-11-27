<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

/**
 * Ported from the old app without `destroy`: users no longer delete their
 * own account. Self-delete skipped the deactivate-first rule admins follow
 * (found issue 8), and with Google-only sign-in the next sign-in would just
 * create the account again. Admins manage accounts in Admin > Users (3e).
 */
class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated())->save();

        return Redirect::route('profile.edit')->with('status', 'Profile saved.');
    }
}
