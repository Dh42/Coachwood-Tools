<?php

namespace App\Tools;

use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * The self-registration convention. Each tool registers itself in a
 * migration, so a deploy that adds a tool also adds its launcher entry and
 * its `tool.<slug>` permission:
 *
 *     return new class extends Migration
 *     {
 *         public function up(): void
 *         {
 *             ToolRegistry::register([
 *                 'slug' => 'po-checker',
 *                 'name' => 'PO Checker',
 *                 'description' => 'Check a POItemExport against Cin7 stock.',
 *                 'group' => 'Amazon · Purchase orders',
 *                 'icon' => 'box',               // an App\View\Icons name, or an emoji
 *                 'url' => '/tools/po-checker',
 *             ]);
 *         }
 *
 *         public function down(): void
 *         {
 *             ToolRegistry::unregister('po-checker');
 *         }
 *     };
 *
 * Registering is insert-only: running it again never overwrites what an
 * admin changed in Admin > Tools. A new tool goes to the end of the order;
 * `legacy:import-platform` then copies the old app's order and active flag.
 *
 * It uses the query builder, not the models, so old migrations keep working
 * whatever the models become.
 */
final class ToolRegistry
{
    /**
     * @param  array{slug: string, name: string, description?: ?string, group?: ?string, icon?: ?string, url?: ?string, active?: bool, sort_order?: int}  $tool
     */
    public static function register(array $tool): void
    {
        $now = now();

        DB::table('tools')->insertOrIgnore([
            'slug' => $tool['slug'],
            'name' => $tool['name'],
            'description' => $tool['description'] ?? null,
            'group' => $tool['group'] ?? null,
            'icon' => $tool['icon'] ?? null,
            'url' => $tool['url'] ?? null,
            'active' => $tool['active'] ?? true,
            'sort_order' => $tool['sort_order'] ?? ((int) DB::table('tools')->max('sort_order') + 1),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('permissions')->insertOrIgnore([
            'name' => 'tool.'.$tool['slug'],
            'guard_name' => 'web',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** Removes the tool, its permission and, through the permission's foreign keys, every grant of it. */
    public static function unregister(string $slug): void
    {
        $permissionIds = DB::table('permissions')->where('name', 'tool.'.$slug)->pluck('id');

        DB::table('model_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('role_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        DB::table('tools')->where('slug', $slug)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
