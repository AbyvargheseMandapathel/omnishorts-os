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
        // Per-channel override for Gemini AI analysis: null = follow the
        // global Settings toggle, true/false = force on/off for this channel.
        Schema::table('channels', function (Blueprint $table) {
            $table->boolean('gemini_enabled')->nullable()->after('post_times');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn('gemini_enabled');
        });
    }
};
