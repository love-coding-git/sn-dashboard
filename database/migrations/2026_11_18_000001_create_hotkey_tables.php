<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Hotkeys page (user 2026-11-18): chain snapshots of netuid 114 read straight from the chain, and the state of each
     * of our hotkeys in every snapshot (uid, emission, deregistration rank) so the page can show how fast the ranks move.
     * Only the newest snapshots keep the full neuron list; older ones keep their summary counts.
     */
    public function up(): void
    {
        Schema::create('chain_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('netuid');
            $table->unsignedBigInteger('block');
            $table->timestamp('taken_at')->index();
            $table->unsignedInteger('immunity_period');
            $table->unsignedInteger('uids');
            $table->unsignedInteger('immune');
            $table->unsignedInteger('zero_emission');
            $table->json('neurons')->nullable();
        });

        Schema::create('hotkey_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chain_snapshot_id')->constrained()->cascadeOnDelete();
            $table->string('name', 64);
            $table->string('hotkey', 64)->index();
            $table->boolean('registered');
            $table->unsignedInteger('uid')->nullable();
            $table->unsignedBigInteger('emission')->nullable();
            $table->unsignedBigInteger('registered_at_block')->nullable();
            $table->boolean('immune')->default(false);
            $table->unsignedInteger('rank')->nullable();
            $table->string('note', 64)->nullable();
            $table->unique(['chain_snapshot_id', 'hotkey']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotkey_states');
        Schema::dropIfExists('chain_snapshots');
    }
};
