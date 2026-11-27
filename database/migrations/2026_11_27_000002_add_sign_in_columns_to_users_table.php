<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The old app's user columns (google_id, a nullable password, disabled_at),
 * so `legacy:import-platform` can copy users as they are. A Google-only or
 * invited user has no password.
 *
 * New here: `theme` and `font_size`, the user's display preferences. Null
 * means "not chosen", and the layouts fall back to the cookies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('google_id')->nullable()->unique()->after('email');
            $table->timestamp('disabled_at')->nullable()->after('email_verified_at');
            $table->string('password')->nullable()->change();
            $table->string('theme', 20)->nullable()->after('remember_token');
            $table->string('font_size', 20)->nullable()->after('theme');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['google_id']);
            $table->dropColumn(['google_id', 'disabled_at', 'theme', 'font_size']);
            $table->string('password')->nullable(false)->change();
        });
    }
};
