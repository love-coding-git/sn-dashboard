<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The NPA/34 watch notes for the /sn34 page (watch-loop stops, Discord rows, family notes - English), kept in the
     * database so the deployed dashboard reads no outside file. `sn34:notes` loads them from the notes file where one exists.
     */
    public function up(): void
    {
        Schema::create('sn34_notes', function (Blueprint $table) {
            $table->id();
            $table->string('name', 40)->unique();
            $table->longText('payload');
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sn34_notes');
    }
};
