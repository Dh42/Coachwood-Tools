<?php

namespace App\View;

/**
 * The sidebar's groups. Until the tools registry is ported (step 3d) this is
 * only Home; 3d replaces it with the tools the signed-in user can open,
 * grouped and ordered as in Admin > Tools.
 *
 * @phpstan-type Item array{label: string, icon: string, href: string, active: bool, badge?: array{count: int, tone: string, label: string}}
 */
final class Navigation
{
    /** @return list<array{title: string, items: list<Item>}> */
    public static function groups(): array
    {
        return [
            ['title' => 'Overview', 'items' => [
                ['label' => 'Home', 'icon' => 'home', 'href' => url('/'), 'active' => request()->is('/')],
            ]],
        ];
    }
}
