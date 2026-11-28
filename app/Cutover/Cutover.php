<?php

namespace App\Cutover;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The cutover groups (config/cutover.php) and which have moved from the old
 * app (`tool_cutovers`). A group that has moved owns its data here: its
 * imports stop, the platform import stops copying its tools' and tasks'
 * settings, and its external writes are allowed (ExternalWrites).
 */
final class Cutover
{
    /** @return array<string, array{label: string, tools: list<string>, imports: list<string>, compares: list<string>, task_prefixes: list<string>}> */
    public static function groups(): array
    {
        return config('cutover.groups');
    }

    /** @return array{label: string, tools: list<string>, imports: list<string>, compares: list<string>, task_prefixes: list<string>} */
    public static function group(string $key): array
    {
        return self::groups()[$key] ?? throw new InvalidArgumentException("Unknown cutover group \"{$key}\". Groups: ".implode(', ', array_keys(self::groups())).'.');
    }

    /**
     * Fails closed: if the table can't be read (no database yet, as in
     * `composer install`), nothing counts as moved.
     */
    public static function hasMoved(string $key): bool
    {
        return in_array($key, self::moved(), true);
    }

    /** @return list<string> */
    public static function moved(): array
    {
        try {
            return DB::table('tool_cutovers')->pluck('group')->all();
        } catch (QueryException) {
            return [];
        }
    }

    public static function movedAt(string $key): ?string
    {
        try {
            return DB::table('tool_cutovers')->where('group', $key)->value('cut_over_at');
        } catch (QueryException) {
            return null;
        }
    }

    public static function groupForTool(string $slug): ?string
    {
        foreach (self::groups() as $key => $group) {
            if (in_array($slug, $group['tools'], true)) {
                return $key;
            }
        }

        return null;
    }

    /** Matched on the command's name, so "asp:backfill CP" belongs to asp-tracker. */
    public static function groupForTask(string $command): ?string
    {
        $name = (string) strtok($command, ' ');
        foreach (self::groups() as $key => $group) {
            foreach ($group['task_prefixes'] as $prefix) {
                if (str_starts_with($name, $prefix)) {
                    return $key;
                }
            }
        }

        return null;
    }

    public static function markMoved(string $key): void
    {
        self::group($key);
        DB::table('tool_cutovers')->updateOrInsert(['group' => $key], ['cut_over_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public static function undo(string $key): void
    {
        self::group($key);
        DB::table('tool_cutovers')->where('group', $key)->delete();
    }
}
