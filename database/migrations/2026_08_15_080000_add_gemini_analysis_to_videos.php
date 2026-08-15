<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->text('ai_hook')->nullable()->after('ai_data');
            $table->string('ai_title')->nullable()->after('ai_hook');
            $table->text('ai_description')->nullable()->after('ai_title');
            $table->json('ai_hashtags')->nullable()->after('ai_description');
            $table->string('ai_thumbnail_text')->nullable()->after('ai_hashtags');
            $table->json('ai_best_moment')->nullable()->after('ai_thumbnail_text');
            $table->string('ai_category')->nullable()->after('ai_best_moment');
            $table->string('ai_target_audience')->nullable()->after('ai_category');
            $table->integer('ai_virality_score')->nullable()->after('ai_target_audience');
            $table->text('ai_improvement')->nullable()->after('ai_virality_score');
            $table->string('analysis_status')->default('none')->after('ai_improvement');
            $table->string('model_used')->nullable()->after('analysis_status');
            $table->timestamp('analyzed_at')->nullable()->after('model_used');
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropColumn([
                'ai_hook',
                'ai_title',
                'ai_description',
                'ai_hashtags',
                'ai_thumbnail_text',
                'ai_best_moment',
                'ai_category',
                'ai_target_audience',
                'ai_virality_score',
                'ai_improvement',
                'analysis_status',
                'model_used',
                'analyzed_at',
            ]);
        });
    }
};
