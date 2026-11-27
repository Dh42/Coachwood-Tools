<?php

namespace App\View;

use Illuminate\Http\Request;

/**
 * The display preferences every layout renders on <html>: the theme and the
 * text size.
 *
 * A signed-in user's saved choice (users.theme, users.font_size) comes first,
 * so it follows them to every device. Otherwise the plain cookies set by
 * resources/js/preferences.js are used. They are left unencrypted
 * (bootstrap/app.php), so the first response already has the right theme,
 * and they keep the sign-in page in the last theme used on that browser.
 *
 * The four themes are the Claude Design canvas "Coachwood Tools Redesign"
 * (Themes artboard).
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
        return self::pick($request ?? request(), 'theme', self::THEMES, self::DEFAULT_THEME);
    }

    public static function fontSize(?Request $request = null): string
    {
        return self::pick($request ?? request(), 'font_size', self::FONT_SIZES, self::DEFAULT_FONT_SIZE);
    }

    /** @param array<string, mixed> $options */
    private static function pick(Request $request, string $key, array $options, string $default): string
    {
        foreach ([$request->user()?->getAttribute($key), $request->cookie($key)] as $value) {
            if (is_string($value) && array_key_exists($value, $options)) {
                return $value;
            }
        }

        return $default;
    }
}
