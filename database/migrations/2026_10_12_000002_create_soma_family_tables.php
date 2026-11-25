<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Owner profiles, their registrations on every subnet, TAO transfers and wallet kinds - what the family view is built from.
     */
    public function up(): void
    {
        Schema::table('owners', function (Blueprint $table) {
            $table->date('created_on')->nullable()->after('label');
            $table->double('balance_tao')->nullable()->after('created_on');
            $table->timestamp('profiled_at')->nullable()->after('balance_tao');
        });

        Schema::create('owner_registrations', function (Blueprint $table) {
            $table->id();
            $table->string('coldkey', 64)->index();
            $table->unsignedSmallInteger('netuid');
            $table->unsignedSmallInteger('uid')->nullable();
            $table->string('hotkey', 64)->index();
            $table->timestamp('registered_at')->nullable();
            $table->double('cost_tao')->nullable();
            $table->unique(['coldkey', 'netuid', 'hotkey', 'registered_at']);
        });

        Schema::create('transfers', function (Blueprint $table) {
            $table->id();
            $table->string('external_id', 64)->unique();
            $table->string('from_address', 64)->index();
            $table->string('to_address', 64)->index();
            $table->double('amount_tao');
            $table->unsignedBigInteger('block_number')->nullable();
            $table->timestamp('transferred_at')->nullable();
        });

        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->string('address', 64)->unique();
            $table->unsignedInteger('transfer_count')->nullable();
            $table->string('kind', 16)->nullable();
            $table->timestamp('checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
        Schema::dropIfExists('transfers');
        Schema::dropIfExists('owner_registrations');
        Schema::table('owners', function (Blueprint $table) {
            $table->dropColumn(['created_on', 'balance_tao', 'profiled_at']);
        });
    }
};
