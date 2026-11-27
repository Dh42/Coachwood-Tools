<?php

namespace App\View;

use App\Models\Tool;
use Illuminate\Support\Str;

/**
 * The sidebar's groups: Home, then the tools the signed-in user can open,
 * grouped and ordered as in Admin > Tools, then Admin for admins.
 *
 * @phpstan-type Item array{label: string, icon: ?string, href: string, active: bool, badge?: array{count: int, tone: string, label: string}}
 */
final class Navigation
{
    /** @return list<array{title: string, items: list<Item>}> */
    public static function groups(): array
    {
        $user = auth()->user();

        $groups = [
            ['title' => 'Overview', 'items' => [
                ['label' => 'Home', 'icon' => 'home', 'href' => route('dashboard'), 'active' => request()->routeIs('dashboard')],
            ]],
        ];

        foreach (Tool::grouped(Tool::visibleTo($user)) as $title => $tools) {
            $items = [];
            foreach ($tools as $tool) {
                if ($tool->url) {
                    $items[] = ['label' => $tool->name, 'icon' => $tool->icon, 'href' => url($tool->url), 'active' => self::isCurrent($tool->url)];
                }
            }
            if ($items !== []) {
                $groups[] = ['title' => $title, 'items' => $items];
            }
        }

        if ($user?->hasRole('admin')) {
            $groups[] = ['title' => 'Admin', 'items' => [
                ['label' => 'Users', 'icon' => 'users', 'href' => route('admin.users.index'), 'active' => request()->routeIs('admin.users.*')],
                ['label' => 'Teams', 'icon' => 'layers', 'href' => route('admin.teams.index'), 'active' => request()->routeIs('admin.teams.*')],
                ['label' => 'Tools', 'icon' => 'sliders', 'href' => route('admin.tools.index'), 'active' => request()->routeIs('admin.tools.*')],
                ['label' => 'Activity', 'icon' => 'clock', 'href' => route('admin.activity.index'), 'active' => request()->routeIs('admin.activity.*')],
                ['label' => 'Mail', 'icon' => 'mail', 'href' => route('admin.mail.show'), 'active' => request()->routeIs('admin.mail.*')],
                ['label' => 'Google Sign-In', 'icon' => 'globe', 'href' => route('admin.google-domains.index'), 'active' => request()->routeIs('admin.google-domains.*')],
            ]];
        }

        return $groups;
    }

    /** The tool's own page or any page under it. */
    private static function isCurrent(string $url): bool
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');

        return $path !== '' && (request()->is($path) || request()->is($path.'/*') || Str::startsWith(request()->path(), $path.'/'));
    }
}
