<?php

namespace App\Models\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Like Laravel's 'encrypted' cast, but an undecryptable value (for example a
 * secret encrypted with an APP_KEY that has since changed) reads back as null
 * instead of crashing the whole page with a DecryptException.
 *
 * The raw ciphertext stays in the database untouched — re-saving a new value
 * simply overwrites it.
 */
class EncryptedNullable implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return null;
        }
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null) {
            return null;
        }

        return Crypt::encryptString($value);
    }
}
