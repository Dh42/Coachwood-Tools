<?php

namespace App\View;

/**
 * Stroke icon paths (24×24, drawn with currentColor), from the Claude Design
 * canvas "Coachwood Tools Redesign". Rendered by <x-icon name="...">.
 */
final class Icons
{
    public const PATHS = [
        'home' => 'M3 11l9-7 9 7v9a1 1 0 0 1-1 1h-5v-6h-6v6H4a1 1 0 0 1-1-1z',
        'eye' => 'M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12zM12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z',
        'tag' => 'M20 12l-8 8-9-9V3h8zM7.5 7.5h.01',
        'receipt' => 'M5 3h14v18l-3-2-2 2-2-2-2 2-2-2-3 2zM9 8h6M9 12h6',
        'trend-down' => 'M3 7l6 6 4-4 8 8M21 11v6h-6',
        'trend-up' => 'M3 17l6-6 4 4 8-8M21 7v6h-6',
        'edit' => 'M4 20h4L19 9l-4-4L4 16zM14 6l4 4',
        'file' => 'M14 3H6v18h12V7zM14 3v4h4M9 13h6M9 17h6',
        'star' => 'M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z',
        'box' => 'M3 7l9-4 9 4v10l-9 4-9-4zM3 7l9 4 9-4M12 11v10',
        'bars' => 'M4 20V10M10 20V4M16 20v-7M2 20h20',
        'layers' => 'M12 3l9 5-9 5-9-5zM3 13l9 5 9-5',
        'cart' => 'M3 4h2l2.5 11h11L21 7H6.5M9 20h.01M18 20h.01',
        'map' => 'M9 4L3 6v14l6-2 6 2 6-2V4l-6 2zM9 4v14M15 6v14',
        'phone' => 'M7 2h10a1 1 0 0 1 1 1v18a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1zM11 18h2',
        'trophy' => 'M8 4h8v5a4 4 0 0 1-8 0zM8 6H4v1a3 3 0 0 0 4 3M16 6h4v1a3 3 0 0 1-4 3M12 13v4M8 21h8M10 17h4v4h-4',
        'pie' => 'M12 3v9h9A9 9 0 1 1 12 3zM15 3.5A9 9 0 0 1 20.5 9H15z',
        'users' => 'M16 20v-1a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v1M9.5 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7zM21 20v-1a4 4 0 0 0-3-3.9M15 4.1a3.5 3.5 0 0 1 0 6.8',
        'sliders' => 'M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M14 4v4M8 10v4M16 16v4',
        'search' => 'M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14zM21 21l-5-5',
        'bell' => 'M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9M10 21h4',
        'alert' => 'M12 3l10 18H2zM12 10v4M12 17h.01',
        'check' => 'M5 12l5 5 9-10',
        'x' => 'M6 6l12 12M18 6L6 18',
        'info' => 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM12 8h.01M11 12h1v5h1',
        'clock' => 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM12 7v5l3 2',
        'inbox' => 'M3 13h5l2 3h4l2-3h5M5 5h14l2 8v6H3v-6z',
        'download' => 'M12 4v11M7 10l5 5 5-5M5 20h14',
        'copy' => 'M9 9h11v11H9zM5 15H4V4h11v1',
        'menu' => 'M4 6h16M4 12h16M4 18h16',
        'chevrons' => 'M8 9l4-4 4 4M8 15l4 4 4-4',
        'chevron-up' => 'M6 15l6-6 6 6',
        'chevron-down' => 'M6 9l6 6 6-6',
        'mail' => 'M3 6h18v12H3zM3 7l9 6 9-6',
        'globe' => 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM3 12h18M12 3c2.5 2.5 3.8 5.5 3.8 9s-1.3 6.5-3.8 9c-2.5-2.5-3.8-5.5-3.8-9S9.5 5.5 12 3z',
        'grip' => 'M9 6h.01M15 6h.01M9 12h.01M15 12h.01M9 18h.01M15 18h.01',
        'logout' => 'M15 4h4v16h-4M10 17l5-5-5-5M15 12H3',
    ];

    public static function has(?string $name): bool
    {
        return $name !== null && isset(self::PATHS[$name]);
    }

    public static function path(string $name): string
    {
        return self::PATHS[$name] ?? self::PATHS['info'];
    }
}
