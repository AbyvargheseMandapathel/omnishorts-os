<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One analytics snapshot for a published post, captured each time the
 * analytics:refresh command (or a publish) fetches fresh stats from YouTube.
 * History drives the dashboard growth curve; the latest values live on
 * Publication::analytics.
 */
class PublicationMetric extends Model
{
    use HasFactory;

    protected $fillable = [
        'publication_id',
        'views',
        'likes',
        'comments',
        'shares',
        'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'views' => 'integer',
            'likes' => 'integer',
            'comments' => 'integer',
            'shares' => 'integer',
            'fetched_at' => 'datetime',
        ];
    }

    public function publication(): BelongsTo
    {
        return $this->belongsTo(Publication::class);
    }
}
