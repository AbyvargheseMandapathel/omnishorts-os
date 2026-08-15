<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Encrypt any legacy plaintext OAuth client secrets stored before the
     * 'encrypted' cast was added. Already-encrypted ciphertext is left alone.
     */
    public function up(): void
    {
        $rows = DB::table('channels')
            ->whereNotNull('google_client_secret')
            ->get(['id', 'google_client_secret']);

        foreach ($rows as $row) {
            $secret = $row->google_client_secret;
            if (str_starts_with($secret, 'eyJ')) {
                continue; // already ciphertext
            }
            DB::table('channels')->where('id', $row->id)->update([
                'google_client_secret' => Crypt::encryptString($secret),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally empty: secrets stay encrypted on rollback.
    }
};
