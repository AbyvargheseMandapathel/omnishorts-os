<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class SocialAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'channel_id',
        'platform',
        'account_name',
        'handle',
        'avatar',
        'follower_count',
        'status',
        'credentials',
        'google_client_id',
        'google_client_secret',
        'posts_per_day',
        'post_times',
    ];

    protected function casts(): array
    {
        return [
            'follower_count' => 'integer',
            'credentials' => 'array',
            'google_client_secret' => 'encrypted',
            'posts_per_day' => 'integer',
            'post_times' => 'array',
        ];
    }

    /**
     * Effective posts per day for this YouTube channel: its own cron if set,
     * otherwise the owning app channel's default, otherwise one per day.
     */
    public function postsPerDay(): int
    {
        if (filled($this->posts_per_day)) {
            return (int) $this->posts_per_day;
        }

        return (int) ($this->channel?->posts_per_day ?? 1);
    }

    /**
     * Effective posting times for this YouTube channel — its own cron wins,
     * falling back to the channel default, then 18:00.
     */
    public function postingTimes(): array
    {
        $times = collect($this->post_times ?? [])
            ->filter()
            ->values()
            ->all();

        if (count($times) === 0) {
            $times = $this->channel?->postingTimes() ?? ['18:00'];
        }

        return array_slice($times, 0, max(1, $this->postsPerDay()));
    }

    /**
     * Whether this YouTube channel has its own cron (rather than using the
     * app channel's default).
     */
    public function hasOwnSchedule(): bool
    {
        return filled($this->post_times);
    }

    public function scheduleLabel(): string
    {
        $times = array_map(
            fn ($t) => Carbon::createFromFormat('H:i', $t)->format('h:i A'),
            $this->postingTimes()
        );

        return count($times).' post'.(count($times) > 1 ? 's' : '').'/day at '.implode(' & ', $times);
    }

    /**
     * Earliest cron slot (date + time) that is not yet occupied by a
     * scheduled publication of THIS YouTube channel — each connected
     * account keeps its own independent calendar.
     */
    public function nextFreeSlot(\Illuminate\Support\Carbon|Carbon|null $from = null): Carbon
    {
        $cursor = $from ? $from->copy()->startOfDay() : now()->copy()->startOfDay();

        $occupied = DB::table('publications')
            ->where('social_account_id', $this->id)
            ->where('status', 'scheduled')
            ->pluck('scheduled_at')
            ->map(fn ($at) => Carbon::parse($at)->format('Y-m-d H:i'))
            ->all();

        for ($day = 0; $day < 60; $day++) {
            foreach ($this->postingTimes() as $time) {
                [$h, $m] = array_map('intval', explode(':', $time));
                $slot = $cursor->copy()->addDays($day)->setHour($h)->setMinute($m)->setSecond(0);
                if ($slot->lessThanOrEqualTo(now())) {
                    continue;
                }
                if (! in_array($slot->format('Y-m-d H:i'), $occupied, true)) {
                    return $slot;
                }
            }
        }

        [$h, $m] = array_map('intval', explode(':', $this->postingTimes()[0]));

        return $cursor->copy()->addDays(60)->setHour($h)->setMinute($m)->setSecond(0);
    }

    /**
     * Whether this YouTube account has its own OAuth client secret stored.
     * The secret itself is never exposed; only this boolean is.
     */
    public function hasGoogleClientSecret(): bool
    {
        return filled($this->getRawOriginal('google_client_secret'));
    }

    /**
     * OAuth credentials for this account, resolved per-account first, then
     * falling back to the owning channel's config, then the app-level .env.
     *
     * @return array{client_id: string|null, client_secret: string|null, source: string}
     */
    public function googleOAuthCredentials(): array
    {
        if ($this->google_client_id && $this->google_client_secret) {
            return [
                'client_id' => $this->google_client_id,
                'client_secret' => $this->google_client_secret,
                'source' => 'account',
            ];
        }

        $channelCreds = $this->channel?->googleOAuthCredentials() ?? [
            'client_id' => null,
            'client_secret' => null,
            'source' => 'env',
        ];

        return $channelCreds;
    }

    /**
     * Flag this account as needing a fresh OAuth grant (e.g. YouTube returned
     * invalid_grant because the refresh token was revoked or expired).
     * Reconnecting (selectYoutubeChannel) flips it back to 'connected'.
     */
    public function markNeedsReconnect(): void
    {
        $this->update(['status' => 'expired']);
    }

    /**
     * Exchange the stored refresh token for a fresh access token. Stores the
     * new token back into credentials. Returns null on failure; flags the
     * account for reconnect on invalid_grant.
     */
    public function freshAccessToken(): ?string
    {
        $creds = $this->googleOAuthCredentials();
        $refreshToken = $this->credentials['refresh_token'] ?? null;

        // No credentials configured — fall back to whatever access token we have.
        if (! $creds['client_id'] || ! $creds['client_secret'] || ! $refreshToken) {
            return $this->credentials['access_token'] ?? null;
        }

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $creds['client_id'],
            'client_secret' => $creds['client_secret'],
        ]);

        if ($response->failed()) {
            if ($response->json('error') === 'invalid_grant') {
                $this->markNeedsReconnect();
            }

            return null;
        }

        $credentials = $this->credentials ?? [];
        $credentials['access_token'] = $response->json('access_token');
        if ($response->json('refresh_token')) {
            $credentials['refresh_token'] = $response->json('refresh_token');
        }
        $this->update(['credentials' => $credentials]);

        return $credentials['access_token'];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function publications(): HasMany
    {
        return $this->hasMany(Publication::class);
    }
}
