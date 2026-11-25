<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('competitions', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedSmallInteger('round')->nullable();
            $table->timestamp('upload_deadline_at')->nullable();
            $table->timestamp('evaluation_end_at')->nullable();
            $table->timestamp('leaderboard_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('miners', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('competition_id');
            $table->foreign('competition_id')->references('id')->on('competitions')->cascadeOnDelete();
            $table->string('hotkey', 64);
            $table->unsignedInteger('place')->nullable();
            $table->string('status', 32)->nullable()->index();
            $table->double('stage1_score')->nullable();
            $table->string('stage1_verdict', 32)->nullable();
            $table->double('stage2_score')->nullable();
            $table->string('stage2_verdict', 32)->nullable();
            $table->double('overall')->nullable();
            $table->double('cat_short')->nullable();
            $table->double('cat_medium')->nullable();
            $table->double('cat_long')->nullable();
            $table->timestamp('last_submit_at', 6)->nullable();
            $table->string('coldkey', 64)->nullable()->index();
            $table->unsignedInteger('uid')->nullable();
            $table->boolean('is_ours')->default(false);
            $table->string('our_label', 8)->nullable();
            $table->json('fingerprint')->nullable();
            $table->timestamp('tasks_synced_at')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamps();
            $table->unique(['competition_id', 'hotkey']);
        });

        Schema::create('miner_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('miner_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('platform_task_id')->nullable();
            $table->string('task_name', 128);
            $table->boolean('is_screener')->default(false);
            $table->unsignedTinyInteger('screener_stage')->nullable();
            $table->string('complexity', 16)->nullable();
            $table->string('benchmark_type', 64)->nullable();
            $table->unsignedSmallInteger('run_count')->nullable();
            $table->unsignedSmallInteger('baseline_passed')->nullable();
            $table->unsignedSmallInteger('baseline_total')->nullable();
            $table->unsignedSmallInteger('compression_passed')->nullable();
            $table->unsignedSmallInteger('compression_total')->nullable();
            $table->unsignedSmallInteger('finished_runs')->default(0);
            $table->double('platform_score')->nullable();
            $table->double('weighted_ratio')->nullable();
            $table->double('mean_steps')->nullable();
            $table->json('tokens')->nullable();
            $table->timestamps();
            $table->unique(['miner_id', 'task_name']);
        });

        Schema::create('task_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('miner_task_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('seq');
            $table->unsignedBigInteger('run_id')->nullable();
            $table->unsignedSmallInteger('attempt_no')->nullable();
            $table->boolean('passed')->nullable();
            $table->unsignedSmallInteger('agent_steps')->nullable();
            $table->unsignedBigInteger('input_tokens')->nullable();
            $table->unsignedBigInteger('cached_input_tokens')->nullable();
            $table->unsignedBigInteger('output_tokens')->nullable();
            $table->unsignedBigInteger('jev_input_tokens')->nullable();
            $table->double('weighted_tokens')->nullable();
            $table->double('time_taken_seconds')->nullable();
            $table->unique(['miner_task_id', 'seq']);
        });

        Schema::create('neurons', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('netuid');
            $table->string('hotkey', 64);
            $table->string('coldkey', 64)->index();
            $table->unsignedSmallInteger('uid')->nullable();
            $table->unsignedBigInteger('registered_at_block')->nullable();
            $table->boolean('registered')->default(true);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->unique(['netuid', 'hotkey']);
        });

        Schema::create('owners', function (Blueprint $table) {
            $table->id();
            $table->string('coldkey', 64)->unique();
            $table->string('family', 16)->index();
            $table->string('label')->nullable();
            $table->timestamps();
        });

        Schema::create('watch_events', function (Blueprint $table) {
            $table->id();
            $table->timestamp('occurred_at')->index();
            $table->string('type', 24);
            $table->unsignedInteger('competition_id')->nullable();
            $table->string('hotkey', 64)->index();
            $table->string('coldkey', 64)->nullable();
            $table->unsignedSmallInteger('uid')->nullable();
            $table->boolean('is_ours')->default(false);
            $table->string('source', 16)->default('queue');
            $table->json('data')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('watch_events');
        Schema::dropIfExists('owners');
        Schema::dropIfExists('neurons');
        Schema::dropIfExists('task_runs');
        Schema::dropIfExists('miner_tasks');
        Schema::dropIfExists('miners');
        Schema::dropIfExists('competitions');
    }
};
