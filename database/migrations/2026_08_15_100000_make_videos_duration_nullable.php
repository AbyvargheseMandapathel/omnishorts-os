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
        // duration used to be a fabricated random number; it is now measured
        // from the real file and null when not probeable — never invented.
        Schema::table('videos', function (Blueprint $table) {
            $table->integer('duration')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->integer('duration')->default(0)->change();
        });
    }
};
