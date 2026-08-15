<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiConnectionContentType extends Model
{
    protected $fillable = ['ai_connection_id', 'content_type'];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(AiConnection::class, 'ai_connection_id');
    }
}
