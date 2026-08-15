<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Publication extends Model
{
    use HasFactory;

    protected $fillable = [
        'video_id',
        'social_account_id',
        'custom_title',
        'custom_caption',
        'custom_hashtags',
        'scheduled_at',
        'published_at',
        'status',
        'post_url',
        'analytics',
        'retry_on_reconnect',
        'attempt_count',
        'next_retry_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'published_at' => 'datetime',
            'analytics' => 'array',
            'retry_on_reconnect' => 'boolean',
            'attempt_count' => 'integer',
            'next_retry_at' => 'datetime',
        ];
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(PublicationMetric::class);
    }
}
