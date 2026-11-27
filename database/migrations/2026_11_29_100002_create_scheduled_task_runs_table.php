<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_task_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scheduled_task_id')->constrained()->cascadeOnDelete();
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->integer('exit_code')->nullable();
            $table->longText('output')->nullable();
            $table->string('triggered_by');
            $table->foreignId('triggered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['scheduled_task_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_task_runs');
    }
};
