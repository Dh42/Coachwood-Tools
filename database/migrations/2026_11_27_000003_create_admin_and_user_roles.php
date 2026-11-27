<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * The two roles every install needs. The old app created them with a seeder
 * that had to be run by hand (RoleSeeder); a migration means a fresh
 * database always has them. Admins pass every permission check through
 * Gate::before in AppServiceProvider.
 */
return new class extends Migration
{
    private const ROLES = ['admin', 'user'];

    public function up(): void
    {
        $now = now();

        foreach (self::ROLES as $name) {
            DB::table('roles')->insertOrIgnore(['name' => $name, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::table('roles')->where('guard_name', 'web')->whereIn('name', self::ROLES)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
