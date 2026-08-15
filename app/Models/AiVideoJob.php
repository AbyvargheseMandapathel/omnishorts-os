<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiVideoJob extends Model
{
    use HasFactory;

    /** Ordered pipeline stages — the job walks through them in this order. */
    public const STAGES = [
        'analyzing' => 'Analyzing video',
        'script' => 'Generating script',
        'images' => 'Generating images',
        'voice' => 'Generating voice',
        'captions' => 'Generating captions',
        'scenes' => 'Preparing scenes',
        'render' => 'Rendering video',
        'finalize' => 'Finalizing',
    ];

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'user_id',
        'channel_id',
        'video_id',
        'content_type',
        'topic',
        'title',
        'description',
        'language',
        'tone',
        'audience',
        'scenes_count',
        'background_path',
        'background_duration',
        'background_width',
        'background_height',
        'auto_approve',
        'status',
        'stage',
        'stage_label',
        'progress',
        'script',
        'scenes',
        'voice',
        'captions_path',
        'final_path',
        'providers_used',
        'error',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'background_duration' => 'float',
            'background_width' => 'integer',
            'background_height' => 'integer',
            'scenes_count' => 'integer',
            'auto_approve' => 'boolean',
            'progress' => 'array',
            'script' => 'array',
            'scenes' => 'array',
            'voice' => 'array',
            'providers_used' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED], true);
    }

    public function markProgress(array $stages, ?string $stage = null, ?string $label = null): void
    {
        // Preserve extra progress keys (audio_mode, ...) alongside the stages.
        $progress = array_merge($this->progress ?? [], ['stages' => $stages]);
        $this->update([
            'progress' => $progress,
            'stage' => $stage ?? $this->stage,
            'stage_label' => $label ?? $this->stage_label,
        ]);
    }

    /**
     * Track which provider was actually used for a generated asset.
     */
    public function noteProviderUsed(string $kind, AiConnection $connection): void
    {
        $used = $this->providers_used ?? [];
        $used[$kind] = [
            'connection' => $connection->name,
            'provider' => $connection->provider,
            'model' => $connection->effectiveModel(),
        ];
        $this->update(['providers_used' => $used]);
    }

    /**
     * Working directory for this job's generated assets on the local 'ai' disk.
     */
    public function workDir(): string
    {
        return "jobs/{$this->id}";
    }
}
