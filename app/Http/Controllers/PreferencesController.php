<?php

namespace App\Http\Controllers;

use App\View\Preferences;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Saves the theme and text size picked in the sidebar to the user, so they
 * follow the user to other devices. Called in the background by
 * resources/js/preferences.js, which has already applied the change.
 */
class PreferencesController extends Controller
{
    public function update(Request $request): Response
    {
        $validated = $request->validate([
            'theme' => ['sometimes', 'string', Rule::in(array_keys(Preferences::THEMES))],
            'font_size' => ['sometimes', 'string', Rule::in(array_keys(Preferences::FONT_SIZES))],
        ]);

        $request->user()->forceFill($validated)->save();

        return response()->noContent();
    }
}
