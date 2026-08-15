<?php

namespace App\Http\Controllers;

use App\Models\Publication;
use App\Models\SocialAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SocialAccountController extends Controller
{
    public function index()
    {
        $channel = Auth::user()->currentChannel();
        if (! $channel) {
            return redirect()->route('onboarding.welcome');
        }

        $accounts = $channel->socialAccounts()
            ->where('platform', 'youtube')
            ->orderBy('created_at')
            ->get();

        return view('accounts.index', compact('channel', 'accounts'));
    }

    /**
     * Each connected YouTube channel gets its own posting cron. Leaving it
     * blank ("use channel default") falls back to the app channel's cron.
     */
    public function updateSchedule(Request $request, SocialAccount $account)
    {
        $channel = Auth::user()->currentChannel();
        if ($account->channel_id !== $channel->id) {
            abort(403);
        }

        $validated = $request->validate([
            'use_channel_default' => ['nullable', 'boolean'],
            'posts_per_day' => ['required_without:use_channel_default', 'nullable', 'integer', 'min:1', 'max:10'],
            'post_times' => ['required_without:use_channel_default', 'nullable', 'array', 'min:1', 'max:10'],
            'post_times.*' => ['required', 'date_format:H:i'],
        ]);

        if ($request->boolean('use_channel_default')) {
            $account->update(['posts_per_day' => null, 'post_times' => null]);
            $message = "{$account->account_name} now uses the channel default cron ({$account->scheduleLabel()}).";
        } else {
            $account->update([
                'posts_per_day' => (int) $validated['posts_per_day'],
                'post_times' => array_values(array_slice($validated['post_times'], 0, (int) $validated['posts_per_day'])),
            ]);
            $message = "{$account->account_name} cron updated: {$account->scheduleLabel()}.";
        }

        return back()->with('success', $message);
    }

    public function disconnect(SocialAccount $account)
    {
        $channel = Auth::user()->currentChannel();
        if ($account->channel_id !== $channel->id) {
            abort(403);
        }

        $accountName = $account->account_name;
        $account->delete();

        return back()->with('success', "Disconnected {$accountName}.");
    }

    public function saveGoogleConfig(Request $request)
    {
        $channel = Auth::user()->currentChannel();
        if (! $channel) {
            return redirect()->route('onboarding.welcome');
        }

        $validated = $request->validate([
            'channel_id' => ['nullable', 'integer', 'exists:channels,id'],
            'account_id' => ['nullable', 'integer', 'exists:social_accounts,id'],
            'google_client_id' => ['nullable', 'string', 'max:255'],
            'google_client_secret' => ['nullable', 'string', 'max:500'],
            'clear_secret' => ['nullable', 'boolean'],
        ]);

        // Saving per-YouTube-channel: account_id targets a specific connected
        // YouTube channel. Otherwise fall back to the app channel (channel
        // switcher modal / YouTube Channels page header).
        if (! empty($validated['account_id'])) {
            $account = $channel->socialAccounts()
                ->where('platform', 'youtube')
                ->findOrFail($validated['account_id']);

            // The secret is never echoed back. Blank = keep saved, unless "remove" is checked.
            $secret = $validated['google_client_secret'];
            if ($secret === null || $secret === '') {
                $secret = ($request->boolean('clear_secret') || ! $account->hasGoogleClientSecret())
                    ? null
                    : $account->google_client_secret;
            }

            $account->update([
                'google_client_id' => $validated['google_client_id'] ?: null,
                'google_client_secret' => $secret,
            ]);

            return redirect()->route('accounts.index')->with('success', "OAuth credentials saved for {$account->account_name}.");
        }

        // Allow editing any of the user's own channels (channel switcher modal);
        // fall back to the active channel for the YouTube Channels page.
        if (! empty($validated['channel_id'])) {
            $channel = Auth::user()->channels()->findOrFail($validated['channel_id']);
        }

        // The secret is never echoed back into the form. A blank secret means
        // "keep the saved one" unless the user explicitly checks "remove".
        $secret = $validated['google_client_secret'];
        if ($secret === null || $secret === '') {
            $secret = ($request->boolean('clear_secret') || ! $channel->hasGoogleClientSecret())
                ? null
                : $channel->google_client_secret;
        }

        $channel->update([
            'google_client_id' => $validated['google_client_id'] ?: null,
            'google_client_secret' => $secret,
        ]);

        return redirect()->route('accounts.index')->with('success', 'Google OAuth credentials saved for this channel.');
    }

    public function googleRedirect(Request $request)
    {
        $channel = Auth::user()->currentChannel();
        if (! $channel) {
            return redirect()->route('onboarding.welcome');
        }

        // Reconnecting a specific YouTube account uses that account's own
        // client + secret; otherwise the channel-level (or env) config applies.
        $account = null;
        if ($request->filled('account_id')) {
            $account = $channel->socialAccounts()
                ->where('platform', 'youtube')
                ->findOrFail($request->input('account_id'));
            $creds = $account->googleOAuthCredentials();
        } else {
            $creds = $channel->googleOAuthCredentials();
        }
        $clientId = $creds['client_id'];
        $clientSecret = $creds['client_secret'];

        if (! $clientId || ! $clientSecret) {
            $message = 'Google OAuth is not configured yet. Add your Client ID and Client Secret below, or set GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET in your .env file.';
            if ($request->boolean('popup')) {
                return redirect()->route('accounts.popup.error', ['retry' => $request->fullUrl()])->with('error', $message);
            }

            return back()->withErrors(['google' => $message]);
        }

        $state = Str::random(32);

        // PKCE: bind the authorization code to this browser session so it
        // cannot be swapped or replayed even if intercepted. Google still
        // requires the client secret for the exchange; PKCE adds defense.
        [$codeVerifier, $codeChallenge] = $this->pkcePair();

        session([
            'youtube_oauth_state' => $state,
            'youtube_oauth_pkce_verifier' => $codeVerifier,
            'youtube_oauth_popup' => $request->boolean('popup'),
            'youtube_oauth_account_id' => $account?->id,
        ]);

        $params = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $this->googleRedirectUri(),
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube.readonly https://www.googleapis.com/auth/yt-analytics.readonly',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ]);

        return redirect('https://accounts.google.com/o/oauth2/v2/auth?'.$params);
    }

    public function googleCallback(Request $request)
    {
        $channel = Auth::user()->currentChannel();
        if (! $channel) {
            return redirect()->route('onboarding.welcome');
        }

        $isPopup = (bool) session('youtube_oauth_popup');

        if ($request->has('error')) {
            return $this->oauthFailureRedirect('Google authorization was cancelled or failed.', $isPopup);
        }

        if (! hash_equals((string) session('youtube_oauth_state', ''), (string) $request->input('state'))) {
            abort(419, 'Invalid OAuth state.');
        }

        // The credentials used to build the auth URL must also be used for the
        // token exchange — a reconnect may carry its own per-account client ID
        // + secret (mirrors googleRedirect). Falls back to the channel config.
        $accountId = (int) session('youtube_oauth_account_id', 0);
        $account = $accountId
            ? $channel->socialAccounts()->where('platform', 'youtube')->find($accountId)
            : null;
        $creds = $account ? $account->googleOAuthCredentials() : $channel->googleOAuthCredentials();

        $tokenResponse = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'code' => $request->input('code'),
            'client_id' => $creds['client_id'],
            'client_secret' => $creds['client_secret'],
            'redirect_uri' => $this->googleRedirectUri(),
            'grant_type' => 'authorization_code',
            'code_verifier' => session()->pull('youtube_oauth_pkce_verifier'),
        ]);

        if ($tokenResponse->failed()) {
            $error = $tokenResponse->json('error');
            $description = $tokenResponse->json('error_description');
            Log::warning('Google OAuth token exchange failed', [
                'error' => $error,
                'error_description' => $description,
                'body' => $tokenResponse->body(),
            ]);
            session()->forget(['youtube_oauth_state', 'youtube_oauth_popup']);

            return $this->oauthFailureRedirect($this->tokenExchangeError($error, $description), $isPopup);
        }

        $tokens = $tokenResponse->json();

        $channelsResponse = Http::withToken($tokens['access_token'])
            ->get('https://www.googleapis.com/youtube/v3/channels', [
                'part' => 'snippet,statistics',
                'mine' => 'true',
            ]);

        if ($channelsResponse->failed()) {
            $reason = data_get($channelsResponse->json(), 'error.errors.0.reason');
            $message = data_get($channelsResponse->json(), 'error.message');
            Log::warning('Google YouTube channels fetch failed', [
                'reason' => $reason,
                'message' => $message,
                'status' => $channelsResponse->status(),
                'body' => $channelsResponse->body(),
            ]);
            session()->forget(['youtube_oauth_state', 'youtube_oauth_popup']);

            return $this->oauthFailureRedirect($this->channelsFetchError($reason, $message), $isPopup);
        }

        $channels = collect($channelsResponse->json('items', []))->map(fn ($item) => [
            'id' => $item['id'],
            'title' => $item['snippet']['title'] ?? 'Untitled Channel',
            'custom_url' => $item['snippet']['customUrl'] ?? null,
            'thumbnail' => $item['snippet']['thumbnails']['default']['url'] ?? null,
            'subscribers' => (int) ($item['statistics']['subscriberCount'] ?? 0),
            'video_count' => (int) ($item['statistics']['videoCount'] ?? 0),
        ]);

        session(['youtube_oauth' => [
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'] ?? null,
            'channels' => $channels->all(),
        ]]);

        return view('accounts.youtube-select', compact('channel', 'channels'));
    }

    public function selectYoutubeChannel(Request $request)
    {
        $channel = Auth::user()->currentChannel();
        if (! $channel) {
            return redirect()->route('onboarding.welcome');
        }

        $oauth = session('youtube_oauth');
        if (! $oauth) {
            return $this->oauthFailureRedirect('Google session expired. Please connect again.', (bool) session('youtube_oauth_popup'));
        }

        $validated = $request->validate([
            'channel_id' => ['required', 'string'],
        ]);

        $selected = collect($oauth['channels'])->firstWhere('id', $validated['channel_id']);
        if (! $selected) {
            $message = 'Please choose one of your YouTube channels.';
            if (session('youtube_oauth_popup')) {
                return redirect()->route('accounts.popup.error')->with('error', $message);
            }

            return back()->withErrors(['channel_id' => $message]);
        }

        SocialAccount::updateOrCreate(
            [
                'channel_id' => $channel->id,
                'platform' => 'youtube',
                'account_name' => $selected['title'],
            ],
            [
                'handle' => $selected['custom_url'] ?? ('@'.($channel->handle ?? 'creator')),
                'avatar' => $selected['thumbnail'],
                'follower_count' => $selected['subscribers'],
                'status' => 'connected',
                'credentials' => [
                    'access_token' => $oauth['access_token'],
                    'refresh_token' => $oauth['refresh_token'],
                    'youtube_channel_id' => $selected['id'],
                ],
            ]
        );

        session()->forget(['youtube_oauth', 'youtube_oauth_state', 'youtube_oauth_account_id']);

        // Re-queue posts that failed while waiting for a fresh grant
        // (invalid_grant) — they'll publish on the next scheduler tick.
        $retryCount = Publication::whereIn(
            'social_account_id',
            $channel->socialAccounts()
                ->where('platform', 'youtube')
                ->where('status', 'connected')
                ->pluck('id')
        )
            ->where('status', 'failed')
            ->where('retry_on_reconnect', true)
            ->update([
                'status' => 'scheduled',
                'retry_on_reconnect' => false,
                'scheduled_at' => now(),
            ]);

        $retryNote = $retryCount > 0
            ? " {$retryCount} queued post".($retryCount === 1 ? '' : 's').' will auto-publish.'
            : '';
        $success = "Connected {$selected['title']} — your scheduled reels will go live there automatically.{$retryNote}";

        $wasPopup = session()->pull('youtube_oauth_popup', false);

        if ($wasPopup) {
            return redirect()->route('accounts.popup.close')->with('success', $success);
        }

        return redirect()->route('accounts.index')->with('success', $success);
    }

    /**
     * Tiny page rendered inside the OAuth popup once a channel is selected:
     * confirms success, reloads the opener, and closes itself.
     */
    public function popupClose()
    {
        return view('accounts.popup-close');
    }

    /**
     * Page rendered inside the OAuth popup when the connection flow fails.
     * Shows the error in the popup, then reloads the opener (with the error
     * passed along) and closes itself.
     */
    public function popupError()
    {
        $retryUrl = request()->query('retry') ?: $this->oauthPopupRetryUrl();

        return view('accounts.popup-error', compact('retryUrl'));
    }

    /**
     * Redirect to the accounts page, or to the popup error page when the flow
     * started in the OAuth popup so the message renders inside the popup
     * window (which then reloads the opener and closes itself).
     */
    private function oauthFailureRedirect(string $message, bool $popup): RedirectResponse
    {
        if (! $popup) {
            return redirect()->route('accounts.index')->with('error', $message);
        }

        return redirect()->route('accounts.popup.error')->with('error', $message);
    }

    /**
     * Connect URL to retry from the popup error page — keeps the account_id
     * when the failed flow was an account reconnect.
     */
    private function oauthPopupRetryUrl(): string
    {
        $params = ['popup' => 1];
        if ($accountId = (int) session('youtube_oauth_account_id', 0)) {
            $params['account_id'] = $accountId;
        }

        return route('accounts.youtube.connect', $params);
    }

    private function googleRedirectUri(): string
    {
        return config('services.google.redirect') ?? route('accounts.youtube.callback');
    }

    /**
     * Human-readable message for a failed OAuth token exchange, with a hint for
     * the configuration mistakes people actually run into.
     */
    private function tokenExchangeError(?string $error, ?string $description): string
    {
        if ($error === 'redirect_uri_mismatch') {
            return "Google rejected the redirect URI. Make sure {$this->googleRedirectUri()} is listed under \"Authorized redirect URIs\" for this OAuth client in the Google Cloud Console.";
        }

        if ($error === 'invalid_client') {
            return 'Google rejected these OAuth credentials (invalid_client). Double-check the Client ID and Client Secret you saved — they must belong to the same OAuth client.';
        }

        if ($error === 'invalid_grant') {
            return 'Google rejected the authorization code (invalid_grant). It may have expired — try connecting again.';
        }

        $detail = $description ?: $error;

        return 'Could not exchange the Google authorization code for tokens.'.($detail ? " Google said: {$detail}" : '');
    }

    /**
     * Human-readable message when the YouTube Data API call fails, mapping the
     * most common API error reasons to the fix for each.
     */
    private function channelsFetchError(?string $reason, ?string $message): string
    {
        if ($reason === 'accessNotConfigured') {
            return 'Could not fetch your YouTube channels — the YouTube Data API v3 is not enabled for your Google Cloud project. Enable it at https://console.cloud.google.com/apis/library/youtube.googleapis.com and try again.';
        }

        if (in_array($reason, ['forbidden', 'insufficientPermissions'], true)) {
            return 'Could not fetch your YouTube channels — this Google account has not granted YouTube permission. Check the OAuth consent screen includes the youtube.upload, youtube.readonly and yt-analytics.readonly scopes, and that the app is out of "Testing" mode (or your account is listed as a test user).';
        }

        if (in_array($reason, ['quotaExceeded', 'dailyLimitExceeded'], true)) {
            return 'Could not fetch your YouTube channels — the YouTube Data API quota for today is exhausted. It resets at midnight Pacific time.';
        }

        $detail = $message ?: $reason;

        return 'Could not fetch YouTube channels for this Google account.'.($detail ? " Google said: {$detail}" : '');
    }

    /**
     * PKCE S256 pair: random verifier + base64url SHA-256 challenge.
     *
     * @return array{0: string, 1: string}
     */
    private function pkcePair(): array
    {
        $verifier = Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return [$verifier, $challenge];
    }
}
