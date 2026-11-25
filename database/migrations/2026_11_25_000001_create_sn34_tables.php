<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The /sn34 page (user 2026-11-25): Yugos runs, verdicts, boards, seat changes, world cards, watch events, owners
     * and their transfers, and the netuid-34 metagraph (the NPA/34 watch notes are read from its notes file). Every table carries the sn34_ prefix.
     */
    public function up(): void
    {
        Schema::create('sn34_runs', function (Blueprint $table) {
            $table->string('run_id', 80)->primary();
            $table->string('hotkey', 64)->index();
            $table->string('coldkey', 64)->nullable()->index();
            $table->string('pack', 64)->nullable()->index();
            $table->string('state', 24)->index();
            $table->timestamp('enqueued_at')->nullable()->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('submission_sha256', 64)->nullable();
            $table->unsignedInteger('started_at_changes')->default(0);
            $table->json('progress')->nullable();
            $table->boolean('complete')->default(false);
            $table->timestamp('progress_at')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sn34_verdicts', function (Blueprint $table) {
            $table->id();
            $table->string('run_id', 80)->unique();
            $table->string('hotkey', 64)->index();
            $table->string('policy_id', 64)->nullable()->index();
            $table->boolean('eligible')->nullable();
            $table->boolean('promoted')->nullable();
            $table->double('net_value')->nullable();
            $table->double('lower_bound')->nullable();
            $table->json('reasons')->nullable();
            $table->json('classes')->nullable();
            $table->timestamp('first_confirmed_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sn34_boards', function (Blueprint $table) {
            $table->id();
            $table->string('policy_id', 64)->nullable();
            $table->timestamp('confirmed_at')->index();
            $table->string('phase', 32)->nullable();
            $table->boolean('paused')->nullable();
            $table->json('kings')->nullable();
            $table->json('published_weights')->nullable();
            $table->json('high_water_marks')->nullable();
            $table->json('queue')->nullable();
            $table->unsignedInteger('challenges')->default(0);
            $table->timestamp('read_at')->nullable();
            $table->unique(['policy_id', 'confirmed_at']);
        });

        Schema::create('sn34_seat_changes', function (Blueprint $table) {
            $table->id();
            $table->timestamp('from_at')->index();
            $table->string('policy_id', 64)->nullable();
            $table->json('seats');
            $table->json('weights')->nullable();
        });

        Schema::create('sn34_cards', function (Blueprint $table) {
            $table->id();
            $table->string('sha256', 64)->unique();
            $table->timestamp('synced_at')->nullable()->index();
            $table->string('source', 200)->nullable();
            $table->json('our_choices')->nullable();
            $table->longText('card')->nullable();
            $table->timestamp('read_at')->nullable();
        });

        Schema::create('sn34_events', function (Blueprint $table) {
            $table->id();
            $table->timestamp('occurred_at')->index();
            $table->string('type', 40)->index();
            $table->string('run_id', 80)->nullable()->index();
            $table->string('hotkey', 64)->nullable();
            $table->string('coldkey', 64)->nullable();
            $table->json('data')->nullable();
        });

        Schema::create('sn34_owners', function (Blueprint $table) {
            $table->id();
            $table->string('coldkey', 64)->unique();
            $table->json('hotkeys')->nullable();
            $table->unsignedInteger('owned_hotkeys')->nullable();
            $table->double('balance_tao')->nullable();
            $table->unsignedInteger('transfers_total')->nullable();
            $table->timestamp('profiled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sn34_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('external_id', 64)->unique();
            $table->string('from_address', 64)->index();
            $table->string('to_address', 64)->index();
            $table->double('amount_tao');
            $table->unsignedBigInteger('block_number')->nullable();
            $table->timestamp('transferred_at')->nullable();
        });

        Schema::create('sn34_wallets', function (Blueprint $table) {
            $table->id();
            $table->string('address', 64)->unique();
            $table->unsignedInteger('transfer_count')->nullable();
            $table->string('kind', 16)->nullable();
            $table->timestamp('checked_at')->nullable();
        });

        Schema::create('sn34_neurons', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('uid')->unique();
            $table->string('hotkey', 64)->index();
            $table->string('coldkey', 64)->index();
            $table->timestamp('registered_at')->nullable();
            $table->double('incentive')->nullable();
            $table->double('emission')->nullable();
            $table->timestamp('read_at')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['sn34_neurons', 'sn34_wallets', 'sn34_transfers', 'sn34_owners', 'sn34_events', 'sn34_cards',
            'sn34_seat_changes', 'sn34_boards', 'sn34_verdicts', 'sn34_runs'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
