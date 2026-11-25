<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Daily mining rewards per hotkey (Taostats daily metagraph snapshots: the day's mining alpha and its TAO value).
     */
    public function up(): void
    {
        Schema::create('earnings', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('netuid');
            $table->date('day');
            $table->string('hotkey', 64);
            $table->string('coldkey', 64)->index();
            $table->double('tao');
            $table->double('alpha')->nullable();
            $table->double('incentive')->nullable();
            $table->unique(['netuid', 'day', 'hotkey']);
            $table->index(['netuid', 'day']);
        });

        Schema::create('earning_fetches', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 96)->unique();    // "114:2026-10-11" (a whole subnet day) or "28:<hotkey>" (one hotkey's history)
            $table->unsignedSmallInteger('rows')->default(0);
            $table->timestamp('fetched_at');
        });

        Schema::table('neurons', function (Blueprint $table) {
            $table->double('daily_tao')->nullable()->after('registered_at_block');
            $table->double('incentive')->nullable()->after('daily_tao');
        });
    }

    public function down(): void
    {
        Schema::table('neurons', function (Blueprint $table) {
            $table->dropColumn(['daily_tao', 'incentive']);
        });
        Schema::dropIfExists('earning_fetches');
        Schema::dropIfExists('earnings');
    }
};
