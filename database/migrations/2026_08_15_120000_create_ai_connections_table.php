<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // text | image | voice — must match the provider's kind.
            $table->string('type');
            // Registry key from config('ai.providers') e.g. groq, huggingface.
            $table->string('provider');
            // Encrypted at rest; never returned by any API/response.
            $table->text('api_key')->nullable();
            $table->string('model')->nullable();
            // For OpenAI-compatible endpoints / other custom base URLs.
            $table->string('base_url')->nullable();
            // Provider-specific knobs (voice, style, temperature, size, ...).
            $table->json('config')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'type']);
        });

        // A connection can be assigned to many content types.
        Schema::create('ai_connection_content_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_connection_id')->constrained()->cascadeOnDelete();
            $table->string('content_type');
            $table->timestamps();

            $table->unique(['ai_connection_id', 'content_type']);
        });

        // Per content type: primary/fallback connection per AI kind.
        Schema::create('ai_content_type_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('content_type');
            $table->string('role');
            $table->foreignId('ai_connection_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'content_type', 'role']);
            $table->index(['user_id', 'content_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_content_type_configs');
        Schema::dropIfExists('ai_connection_content_types');
        Schema::dropIfExists('ai_connections');
    }
};
