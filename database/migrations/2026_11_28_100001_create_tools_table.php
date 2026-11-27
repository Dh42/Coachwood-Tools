<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tools registry: the old app's `tools` table (three migrations folded
 * into one: create, description, unique url), plus `group`, new here. The
 * design groups the sidebar and launcher ("Amazon · Pricing", "BioSteel
 * Teams"); null shows under "Tools".
 *
 * Each tool adds its own row and `tool.<slug>` permission in a registration
 * migration (App\Tools\ToolRegistry). `legacy:import-platform` then copies
 * the old app's order and active flag onto the tools registered here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tools', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('slug')->unique();
            $table->string('group')->nullable();
            $table->string('icon')->nullable();
            $table->string('url')->nullable()->unique();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tools');
    }
};
