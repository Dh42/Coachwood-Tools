<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which cutover groups (config/cutover.php) have moved from the old app,
 * and when. A row means this app owns that group's data and may write to
 * outside systems for it. Set by `cutover:move`, cleared by `cutover:undo`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tool_cutovers', function (Blueprint $table) {
            $table->id();
            $table->string('group', 60)->unique();
            $table->dateTime('cut_over_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tool_cutovers');
    }
};
