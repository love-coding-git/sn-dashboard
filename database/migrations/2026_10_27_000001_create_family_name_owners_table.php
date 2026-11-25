<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every coldkey a family name was given to, so a name freed by a merge is never given to an unrelated family
     * (2026-10-27: "Pine" moved from 5FBvtucf's family to 5CdCkDmv).
     */
    public function up(): void
    {
        Schema::create('family_name_owners', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64)->index();
            $table->string('coldkey', 64);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['name', 'coldkey']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('family_name_owners');
    }
};
