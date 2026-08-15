<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Channel extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'handle',
        'category',
        'description',
        'profile_image',
        'cover_image',
        'status',
        'posts_per_day',
        'post_times',
        'google_client_id',
        'google_client_secret',
    ];

    protected function casts(): array
    {
        return [
            'posts_per_day' => 'integer',
            'post_times' => 'array',
            'google_client_secret' => 'encrypted',
        ];
    }

    /**
     * Whether this channel has its own OAuth client secret stored (ciphertext present).
     * The secret itself is never exposed; only this boolean is.
     */
    public function hasGoogleClientSecret(): bool
    {
        return filled($this->getRawOriginal('google_client_secret'));
    }

    /**
     * Google OAuth credentials for this channel, falling back to the app-level .env config.
     *
     * @return array{client_id: string|null, client_secret: string|null, source: string}
     */
    public function googleOAuthCredentials(): array
    {
        if ($this->google_client_id && $this->google_client_secret) {
            return [
                'client_id' => $this->google_client_id,
                'client_secret' => $this->google_client_secret,
                'source' => 'channel',
            ];
        }

        return [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'source' => 'env',
        ];
    }

    /**
     * Effective posting times for this channel (fallback: one post at 18:00).
     */
    public function postingTimes(): array
    {
        $times = collect($this->post_times ?? [])
            ->filter()
            ->values()
            ->all();

        if (count($times) === 0) {
            return ['18:00'];
        }

        return array_slice($times, 0, max(1, $this->posts_per_day ?? 1));
    }

    /**
     * Earliest cron slot (date + time) that is not yet occupied by a scheduled publication.
     */
    public function nextFreeSlot(\Illuminate\Support\Carbon|\Carbon\Carbon|null $from = null): \Carbon\Carbon
    {
        $cursor = $from ? $from->copy()->startOfDay() : now()->copy()->startOfDay();

        $occupied = \Illuminate\Support\Facades\DB::table('publications')
            ->join('videos', 'videos.id', '=', 'publications.video_id')
            ->where('videos.channel_id', $this->id)
            ->where('publications.status', 'scheduled')
            ->pluck('publications.scheduled_at')
            ->map(fn ($at) => \Carbon\Carbon::parse($at)->format('Y-m-d H:i'))
            ->all();

        for ($day = 0; $day < 60; $day++) {
            foreach ($this->postingTimes() as $time) {
                [$h, $m] = array_map('intval', explode(':', $time));
                $slot = $cursor->copy()->addDays($day)->setHour($h)->setMinute($m)->setSecond(0);
                if ($slot->lessThanOrEqualTo(now())) {
                    continue;
                }
                if (!in_array($slot->format('Y-m-d H:i'), $occupied, true)) {
                    return $slot;
                }
            }
        }

        [$h, $m] = array_map('intval', explode(':', $this->postingTimes()[0]));

        return $cursor->copy()->addDays(60)->setHour($h)->setMinute($m)->setSecond(0);
    }

    public function scheduleLabel(): string
    {
        $times = array_map(
            fn ($t) => \Carbon\Carbon::createFromFormat('H:i', $t)->format('h:i A'),
            $this->postingTimes()
        );

        return count($times) . ' post' . (count($times) > 1 ? 's' : '') . '/day at ' . implode(' & ', $times);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function videos(): HasMany
    {
        return $this->hasMany(Video::class);
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }
}
