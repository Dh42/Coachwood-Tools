<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The company's Google Workspace domains (Admin > Google Sign-In, step 3f).
 * Here in 3b because password reset already refuses these addresses: a
 * company account signs in with Google, never with a password.
 *
 * The old migration also copied GOOGLE_ALLOWED_DOMAIN from the env. Not
 * needed here: `legacy:import-platform` copies the domains.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_workspace_domains', function (Blueprint $table) {
            $table->id();
            $table->string('domain')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_workspace_domains');
    }
};
