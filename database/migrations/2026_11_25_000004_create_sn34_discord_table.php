<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Messages of the Yugos subnet's Discord channel (#34 mind), read by SyncDiscord through Discord's REST API (the
     * reader of ai_tr/tools/discord_watch.py). One row per Discord message id; an edit updates the text.
     */
    public function up(): void
    {
        Schema::create('sn34_discord', function (Blueprint $table) {
            $table->id();
            $table->string('message_id', 32)->unique();
            $table->string('channel', 32)->index();
            $table->string('author', 120)->nullable();
            $table->timestamp('posted_at')->index();
            $table->text('text')->nullable();
            $table->string('reply_author', 120)->nullable();
            $table->text('reply_text')->nullable();
            $table->json('links')->nullable();
            $table->string('url', 300)->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('received_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sn34_discord');
    }
};
