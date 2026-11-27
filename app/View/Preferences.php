<?php

namespace App\View;

use Illuminate\Http\Request;

/**
 * The display preferences every layout renders on <html>: the theme and the
 * text size. Both are plain cookies set by resources/js/preferences.js and
 * left unencrypted (bootstrap/app.php), so the first response already has
 * the right theme.
 *
 * The four themes are the Claude Design canvas "Coachwood Tools Redesign"
 * (Themes artboard). Step 3b adds a per-user theme, read before the cookie.
 */
final class Preferences
{
    public const THEMES = [
        'midnight' => ['name' => 'Midnight', 'description' => 'Deep navy with a lavender accent.'],
        'graphite' => ['name' => 'Graphite', 'description' => 'Neutral charcoal with a blue accent.'],
        'daylight' => ['name' => 'Daylight', 'description' => 'Light, for bright rooms, printing and screen-shares.'],
        'evergreen' => ['name' => 'Evergreen', 'description' => 'Green-black with a mint accent.'],
    ];

    public const DEFAULT_THEME = 'midnight';

    public const FONT_SIZES = ['normal' => 'Normal', 'large' => 'Large'];

    public const DEFAULT_FONT_SIZE = 'normal';

    public static function theme(?Request $request = null): string
    {
        $value = ($request ?? request())->cookie('theme');

        return is_string($value) && array_key_exists($value, self::THEMES) ? $value : self::DEFAULT_THEME;
    }

    public static function fontSize(?Request $request = null): string
    {
        $value = ($request ?? request())->cookie('font_size');

        return is_string($value) && array_key_exists($value, self::FONT_SIZES) ? $value : self::DEFAULT_FONT_SIZE;
    }
}
