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
        Schema::table('shares', function (Blueprint $table) {
            // The details a password-protected share keeps to itself until it is unlocked:
            // any of files, stats, message, sender, name (see Share::LOCKED_HIDEABLE)
            $table->json('locked_hidden')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shares', function (Blueprint $table) {
            $table->dropColumn('locked_hidden');
        });
    }
};
