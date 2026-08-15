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
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->string('platform'); // youtube, instagram, tiktok, facebook, twitter
            $table->string('account_name');
            $table->string('handle')->nullable();
            $table->string('avatar')->nullable();
            $table->unsignedBigInteger('follower_count')->default(0);
            $table->string('status')->default('connected'); // connected, disconnected, expired
            $table->json('credentials')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};

