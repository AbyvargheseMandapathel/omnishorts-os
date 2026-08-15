<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Video extends Model
{
    use HasFactory;

    protected $fillable = [
        'channel_id',
        'title',
        'description',
        'file_path',
        'thumbnail_path',
        'duration',
        'status',
        'ai_data',
        'ai_hook',
        'ai_title',
        'ai_description',
        'ai_hashtags',
        'ai_thumbnail_text',
        'ai_best_moment',
        'ai_category',
        'ai_target_audience',
        'ai_virality_score',
        'ai_improvement',
        'analysis_status',
        'model_used',
        'analyzed_at',
    ];

    protected function casts(): array
    {
        return [
            // duration is intentionally not cast: null means "not probed yet"
            // and must survive round-trips instead of collapsing to 0.
            'ai_data' => 'array',
            'ai_hashtags' => 'array',
            'ai_best_moment' => 'array',
            'ai_virality_score' => 'integer',
            'analyzed_at' => 'datetime',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function publications(): HasMany
    {
        return $this->hasMany(Publication::class);
    }

    /**
     * Public playback URL for the uploaded file, or null when there is no
     * file or the disk no longer has it (e.g. pruned). Works with any
     * configured video disk (public, ftp via FTP_URL, s3, ...).
     */
    public function playbackUrl(): ?string
    {
        if (! $this->file_path) {
            return null;
        }

        $disk = (string) config('filesystems.video_disk', 'public');
        if (! Storage::disk($disk)->exists($this->file_path)) {
            return null;
        }

        return Storage::disk($disk)->url($this->file_path);
    }
}
