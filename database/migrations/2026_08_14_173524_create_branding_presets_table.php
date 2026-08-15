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
        Schema::create('branding_presets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->string('name')->default('Default');
            $table->string('logo_path')->nullable();
            $table->string('watermark_path')->nullable();
            $table->string('primary_color')->default('#6366f1');
            $table->string('secondary_color')->default('#ec4899');
            $table->string('font_family')->default('Inter');
            $table->string('hook_style')->default('bold_yellow');
            $table->string('caption_style')->default('hormozi_pop');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('branding_presets');
    }
};

