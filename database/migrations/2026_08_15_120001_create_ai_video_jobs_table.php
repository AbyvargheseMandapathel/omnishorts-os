<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_video_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            // Set when the user approves the rendered video — links the job to
            // the existing Content Library / scheduling / publishing workflow.
            $table->foreignId('video_id')->nullable()->constrained()->nullOnDelete();

            $table->string('content_type')->default('video');
            $table->string('topic');
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('language')->default('en');
            $table->string('tone')->default('engaging');
            $table->string('audience')->nullable();
            $table->integer('scenes_count')->default(5);

            // Background video — stored on the app's configured video disk.
            $table->string('background_path');
            $table->decimal('background_duration', 10, 3)->nullable();
            $table->integer('background_width')->nullable();
            $table->integer('background_height')->nullable();

            $table->string('status')->default('queued'); // queued|running|completed|failed|cancelled
            $table->string('stage')->nullable();         // analyzing|script|images|voice|captions|scenes|render|finalize
            $table->string('stage_label')->nullable();
            $table->json('progress')->nullable();
            $table->json('script')->nullable();
            $table->json('scenes')->nullable();
            $table->json('voice')->nullable();
            $table->string('captions_path')->nullable();
            $table->string('final_path')->nullable();
            $table->json('providers_used')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'user_id']);
            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_video_jobs');
    }
};
