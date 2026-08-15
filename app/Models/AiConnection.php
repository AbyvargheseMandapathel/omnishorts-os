<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiConnection extends Model
{
    use HasFactory;

    public const TYPES = ['text', 'image', 'voice'];

    protected $fillable = [
        'user_id',
        'name',
        'type',
        'provider',
        'api_key',
        'model',
        'base_url',
        'config',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            // Encrypted at rest with the app key — never exposed to the
            // browser, JSON responses, or logs. Undecryptable ciphertext
            // (e.g. from a changed APP_KEY) reads back as null instead of
            // crashing pages.
            'api_key' => Casts\EncryptedNullable::class,
            'config' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Content types this connection is available for.
     */
    public function contentTypeAssignments(): HasMany
    {
        return $this->hasMany(AiConnectionContentType::class);
    }

    public function assignedContentTypes(): array
    {
        // Insertion order = the order the user picked them (not the unique
        // index order), so the settings list stays stable.
        return $this->contentTypeAssignments()->orderBy('id')->pluck('content_type')->all();
    }

    public function isAssignedTo(string $contentType): bool
    {
        return in_array($contentType, $this->assignedContentTypes(), true);
    }

    /**
     * Replace the content-type assignments with the given list.
     */
    public function syncContentTypes(array $contentTypes): void
    {
        $this->contentTypeAssignments()->delete();
        foreach (array_unique(array_values($contentTypes)) as $type) {
            $this->contentTypeAssignments()->create(['content_type' => $type]);
        }
    }

    public function hasApiKey(): bool
    {
        return filled($this->api_key);
    }

    /**
     * Effective model — connection override, then provider default.
     */
    public function effectiveModel(): ?string
    {
        return $this->model ?: (string) (config("ai.defaults.{$this->provider}.model") ?? '');
    }

    /**
     * Effective base URL — connection override, then provider default (e.g.
     * Pollinations sets its own gateway so an OpenAI-compatible provider can
     * be reused without the user typing a URL).
     */
    public function effectiveBaseUrl(): ?string
    {
        return $this->base_url
            ?: (config("ai.defaults.{$this->provider}.base_url") ?: null);
    }
}
