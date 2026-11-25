<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Our registered SN34 hotkeys on the /sn34 page (user 2026-11-25): registration block, the block of the read,
     * immunity and danger flags, and daily mining alpha / TAO from the Taostats metagraph (registered_at stays an
     * estimate from blocks x 12 s).
     */
    public function up(): void
    {
        Schema::table('sn34_neurons', function (Blueprint $table) {
            $table->unsignedBigInteger('registered_at_block')->nullable()->after('registered_at');
            $table->unsignedBigInteger('block_number')->nullable()->after('registered_at_block');
            $table->boolean('immune')->nullable()->after('block_number');
            $table->boolean('in_danger')->nullable()->after('immune');
            $table->double('daily_alpha')->nullable()->after('emission');
            $table->double('daily_tao')->nullable()->after('daily_alpha');
        });
    }

    public function down(): void
    {
        Schema::table('sn34_neurons', function (Blueprint $table) {
            $table->dropColumn(['registered_at_block', 'block_number', 'immune', 'in_danger', 'daily_alpha', 'daily_tao']);
        });
    }
};
