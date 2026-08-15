<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mark publications that failed because the YouTube token was revoked
     * (invalid_grant) so they can be re-queued automatically once the user
     * reconnects the account.
     */
    public function up(): void
    {
        Schema::table('publications', function (Blueprint $table) {
            $table->boolean('retry_on_reconnect')->default(false)->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('publications', function (Blueprint $table) {
            $table->dropColumn('retry_on_reconnect');
        });
    }
};
