<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_video_jobs', function (Blueprint $table) {
            // Daily auto-generation jobs: land the finished render straight
            // into the Content Library without manual approval.
            $table->boolean('auto_approve')->default(false)->after('background_height');
        });
    }

    public function down(): void
    {
        Schema::table('ai_video_jobs', function (Blueprint $table) {
            $table->dropColumn('auto_approve');
        });
    }
};
