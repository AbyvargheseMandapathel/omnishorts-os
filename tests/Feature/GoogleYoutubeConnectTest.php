<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleYoutubeConnectTest extends TestCase
{
    use RefreshDatabase;

    private function createUserWithChannel(): array
    {
        $user = User::factory()->create();
        $channel = Channel::create(['user_id' => $user->id, 'name' => 'Test Channel']);

        return [$user, $channel];
    }

    public function test_google_redirect_builds_oauth_url(): void
    {
        [$user] = $this->createUserWithChannel();
        config([
            'services.google.client_id' => 'client-123',
            'services.google.client_secret' => 'secret-123',
        ]);

        $response = $this->actingAs($user)->get(route('accounts.youtube.connect'));

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringContainsString('https://accounts.google.com/o/oauth2/v2/auth', $location);
        $this->assertStringContainsString('client_id=client-123', $location);
        $this->assertStringContainsString('access_type=offline', $location);
        $this->assertStringContainsString('prompt=consent', $location);
        $this->assertStringContainsString('youtube.upload', $location);
        $this->assertStringContainsString('yt-analytics.readonly', $location);
        $this->assertStringContainsString('code_challenge_method=S256', $location);
        $this->assertStringContainsString('code_challenge=', $location);
        $this->assertNotNull(session('youtube_oauth_state'));
        $this->assertNotNull(session('youtube_oauth_pkce_verifier'));
    }

    public function test_google_redirect_requires_configuration(): void
    {
        [$user] = $this->createUserWithChannel();
        config(['services.google.client_id' => null, 'services.google.client_secret' => null]);

        $response = $this->actingAs($user)->get(route('accounts.youtube.connect'));

        $response->assertSessionHasErrors('google');
    }

    public function test_channel_level_oauth_credentials_override_env(): void
    {
        [$user, $channel] = $this->createUserWithChannel();

        // Env-level creds are set but channel has its own — channel must win.
        config([
            'services.google.client_id' => 'env-client',
            'services.google.client_secret' => 'env-secret',
        ]);
        $channel->update([
            'google_client_id' => 'channel-client',
            'google_client_secret' => 'channel-secret',
        ]);

        $creds = $channel->fresh()->googleOAuthCredentials();
        $this->assertSame('channel', $creds['source']);
        $this->assertSame('channel-client', $creds['client_id']);

        $response = $this->actingAs($user)->get(route('accounts.youtube.connect'));
        $this->assertStringContainsString('client_id=channel-client', $response->headers->get('Location'));
    }

    public function test_save_google_config_stores_secret_encrypted(): void
    {
        [$user, $channel] = $this->createUserWithChannel();

        $response = $this->actingAs($user)->post(route('accounts.google.config'), [
            'google_client_id' => 'my-client-123.apps.googleusercontent.com',
            'google_client_secret' => 'GOCSPX-secret',
        ]);

        $response->assertRedirect(route('accounts.index'));
        $channel->refresh();
        $this->assertSame('my-client-123.apps.googleusercontent.com', $channel->google_client_id);
        // Decrypts back to the original value...
        $this->assertSame('GOCSPX-secret', $channel->google_client_secret);
        // ...but the DB stores ciphertext, never the plaintext.
        $this->assertNotSame('GOCSPX-secret', $channel->getRawOriginal('google_client_secret'));
        $this->assertTrue($channel->hasGoogleClientSecret());
        $this->assertSame('channel', $channel->googleOAuthCredentials()['source']);
    }

    public function test_save_google_config_blank_keeps_saved_secret(): void
    {
        [$user, $channel] = $this->createUserWithChannel();
        $channel->update([
            'google_client_id' => 'my-client-123.apps.googleusercontent.com',
            'google_client_secret' => 'GOCSPX-secret',
        ]);

        // Blank secret + no clear flag = saved secret stays.
        $response = $this->actingAs($user)->post(route('accounts.google.config'), [
            'google_client_id' => 'my-client-123.apps.googleusercontent.com',
            'google_client_secret' => '',
        ]);

        $response->assertRedirect(route('accounts.index'));
        $channel->refresh();
        $this->assertSame('GOCSPX-secret', $channel->google_client_secret);
        $this->assertTrue($channel->hasGoogleClientSecret());
    }

    public function test_accounts_page_never_echoes_saved_secret(): void
    {
        [$user, $channel] = $this->createUserWithChannel();
        $channel->update([
            'google_client_id' => 'my-client-123.apps.googleusercontent.com',
            'google_client_secret' => 'GOCSPX-top-secret',
        ]);

        $response = $this->actingAs($user)->get(route('accounts.index'));

        $response->assertOk();
        $response->assertSee('••• saved •••', false);
        $response->assertDontSee('GOCSPX-top-secret');
        $response->assertSee('my-client-123.apps.googleusercontent.com', false);
    }

    public function test_save_google_config_can_target_any_user_channel(): void
    {
        [$user] = $this->createUserWithChannel();
        $second = Channel::create(['user_id' => $user->id, 'name' => 'Second Channel']);

        $response = $this->actingAs($user)->post(route('accounts.google.config'), [
            'channel_id' => $second->id,
            'google_client_id' => 'second-client.apps.googleusercontent.com',
            'google_client_secret' => 'GOCSPX-second',
        ]);

        $response->assertRedirect(route('accounts.index'));
        $second->refresh();
        $this->assertSame('second-client.apps.googleusercontent.com', $second->google_client_id);
        $this->assertSame('GOCSPX-second', $second->google_client_secret);
        $this->assertTrue($second->hasGoogleClientSecret());
    }

    public function test_save_google_config_rejects_other_users_channel(): void
    {
        [$user] = $this->createUserWithChannel();
        $otherUser = User::factory()->create();
        $foreignChannel = Channel::create(['user_id' => $otherUser->id, 'name' => 'Foreign Channel']);

        $response = $this->actingAs($user)->post(route('accounts.google.config'), [
            'channel_id' => $foreignChannel->id,
            'google_client_id' => 'hacker-client',
            'google_client_secret' => 'GOCSPX-hack',
        ]);

        $response->assertNotFound();
        $foreignChannel->refresh();
        $this->assertNull($foreignChannel->google_client_id);
        $this->assertNull($foreignChannel->google_client_secret);
    }

    public function test_account_level_oauth_credentials_resolve_first(): void
    {
        [$user, $channel] = $this->createUserWithChannel();
        $account = SocialAccount::create([
            'channel_id' => $channel->id,
            'platform' => 'youtube',
            'account_name' => 'Owned YT',
            'status' => 'connected',
        ]);
        $channel->update([
            'google_client_id' => 'channel-client',
            'google_client_secret' => 'channel-secret',
        ]);
        $account->update([
            'google_client_id' => 'account-client',
            'google_client_secret' => 'account-secret',
        ]);

        $creds = $account->fresh()->googleOAuthCredentials();
        $this->assertSame('account', $creds['source']);
        $this->assertSame('account-client', $creds['client_id']);
        $this->assertSame('account-secret', $creds['client_secret']);
        $this->assertNotSame('account-secret', $account->getRawOriginal('google_client_secret'));
        $this->assertTrue($account->hasGoogleClientSecret());
    }

    public function test_account_oauth_falls_back_to_channel_then_env(): void
    {
        [$user, $channel] = $this->createUserWithChannel();
        $account = SocialAccount::create([
            'channel_id' => $channel->id,
            'platform' => 'youtube',
            'account_name' => 'No Own Creds',
            'status' => 'connected',
        ]);

        config([
            'services.google.client_id' => 'env-client',
            'services.google.client_secret' => 'env-secret',
        ]);
        $this->assertSame('env', $account->googleOAuthCredentials()['source']);

        $channel->update(['google_client_id' => 'channel-client', 'google_client_secret' => 'channel-secret']);
        $this->assertSame('channel', $account->fresh()->googleOAuthCredentials()['source']);
    }

    public function test_save_google_config_targets_youtube_account(): void
    {
        [$user, $channel] = $this->createUserWithChannel();
        $account = SocialAccount::create([
            'channel_id' => $channel->id,
            'platform' => 'youtube',
            'account_name' => 'Tech Pulse Shorts',
            'status' => 'connected',
        ]);

        $response = $this->actingAs($user)->post(route('accounts.google.config'), [
            'account_id' => $account->id,
            'google_client_id' => 'yt-client.apps.googleusercontent.com',
            'google_client_secret' => 'GOCSPX-yt',
        ]);

        $response->assertRedirect(route('accounts.index'));
        $account->refresh();
        $this->assertSame('yt-client.apps.googleusercontent.com', $account->google_client_id);
        $this->assertSame('GOCSPX-yt', $account->google_client_secret);
        $this->assertNotSame('GOCSPX-yt', $account->getRawOriginal('google_client_secret'));
        $channel->refresh();
        $this->assertNull($channel->google_client_id);
    }

    public function test_google_redirect_reconnect_uses_account_credentials(): void
    {
        [$user, $channel] = $this->createUserWithChannel();
        $account = SocialAccount::create([
            'channel_id' => $channel->id,
            'platform' => 'youtube',
            'account_name' => 'Tech Pulse Shorts',
            'status' => 'connected',
        ]);
        $account->update([
            'google_client_id' => 'account-client',
            'google_client_secret' => 'account-secret',
        ]);

        $response = $this->actingAs($user)->get(route('accounts.youtube.connect', ['account_id' => $account->id]));

        $response->assertRedirect();
        $this->assertStringContainsString('client_id=account-client', $response->headers->get('Location'));
        $this->assertTrue(session('youtube_oauth_account_id') === $account->id);
    }

    public function test_popup_flow_redirects_to_popup_close(): void
    {
        [$user, $channel] = $this->createUserWithChannel();

        session([
            'youtube_oauth_popup' => true,
            'youtube_oauth' => [
                'access_token' => 'access-tok',
                'refresh_token' => 'refresh-tok',
                'channels' => [
                    ['id' => 'UC-ONE', 'title' => 'Tech Pulse', 'custom_url' => '@techpulse', 'thumbnail' => null, 'subscribers' => 1, 'video_count' => 1],
                ],
            ],
        ]);

        $response = $this->actingAs($user)->post(route('accounts.youtube.select'), [
            'channel_id' => 'UC-ONE',
        ]);

        $response->assertRedirect(route('accounts.popup.close'));
        $this->assertNull(session('youtube_oauth_popup'));
        $this->assertSame('Tech Pulse', SocialAccount::where('channel_id', $channel->id)->first()->account_name);
    }

    public function test_save_google_config_clear_secret_removes_it(): void
    {
        [$user, $channel] = $this->createUserWithChannel();
        $channel->update([
            'google_client_id' => 'old-client',
            'google_client_secret' => 'old-secret',
        ]);
        config([
            'services.google.client_id' => 'env-client',
            'services.google.client_secret' => 'env-secret',
        ]);

        $response = $this->actingAs($user)->post(route('accounts.google.config'), [
            'google_client_id' => '',
            'google_client_secret' => '',
            'clear_secret' => '1',
        ]);

        $response->assertRedirect(route('accounts.index'));
        $channel->refresh();
        $this->assertNull($channel->google_client_id);
        $this->assertNull($channel->google_client_secret);
        $this->assertFalse($channel->hasGoogleClientSecret());
        $this->assertSame('env', $channel->googleOAuthCredentials()['source']);
    }

    public function test_google_callback_shows_channels_for_selection(): void
    {
        [$user] = $this->createUserWithChannel();
        config([
            'services.google.client_id' => 'client-123',
            'services.google.client_secret' => 'secret-123',
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'access-tok',
                'refresh_token' => 'refresh-tok',
                'expires_in' => 3600,
            ]),
            'www.googleapis.com/*' => Http::response([
                'items' => [
                    [
                        'id' => 'UC-CHANNEL-1',
                        'snippet' => [
                            'title' => 'Tech Pulse',
                            'customUrl' => '@techpulse',
                            'thumbnails' => ['default' => ['url' => 'http://img/tech']],
                        ],
                        'statistics' => ['subscriberCount' => '45200', 'videoCount' => '310'],
                    ],
                    [
                        'id' => 'UC-CHANNEL-2',
                        'snippet' => [
                            'title' => 'Gaming Zone',
                            'customUrl' => '@gamingzone',
                            'thumbnails' => ['default' => ['url' => 'http://img/game']],
                        ],
                        'statistics' => ['subscriberCount' => '1200', 'videoCount' => '88'],
                    ],
                ],
            ]),
        ]);

        session([
            'youtube_oauth_state' => 'valid-state',
            'youtube_oauth_pkce_verifier' => 'test-code-verifier',
        ]);

        $response = $this->actingAs($user)->get(route('accounts.youtube.callback', [
            'code' => 'auth-code',
            'state' => 'valid-state',
        ]));

        $response->assertOk();
        $response->assertViewHas('channels', function ($channels) {
            return $channels->count() === 2 && $channels->first()['title'] === 'Tech Pulse';
        });
        $this->assertSame('access-tok', session('youtube_oauth')['access_token']);

        // The token exchange must carry the PKCE verifier from the auth request.
        Http::assertSent(function ($request) {
            return $request->url() === 'https://oauth2.googleapis.com/token'
                && $request->data()['code_verifier'] === 'test-code-verifier'
                && $request->data()['client_secret'] === 'secret-123';
        });
    }

    public function test_google_callback_rejects_invalid_state(): void
    {
        [$user] = $this->createUserWithChannel();
        session(['youtube_oauth_state' => 'real-state']);

        $response = $this->actingAs($user)->get(route('accounts.youtube.callback', [
            'code' => 'auth-code',
            'state' => 'forged-state',
        ]));

        $response->assertStatus(419);
    }

    public function test_google_callback_uses_account_credentials_when_reconnecting(): void
    {
        [$user, $channel] = $this->createUserWithChannel();
        $account = SocialAccount::create([
            'channel_id' => $channel->id,
            'platform' => 'youtube',
            'account_name' => 'Tech Pulse Shorts',
            'status' => 'connected',
        ]);
        $channel->update([
            'google_client_id' => 'channel-client',
            'google_client_secret' => 'channel-secret',
        ]);
        $account->update([
            'google_client_id' => 'account-client',
            'google_client_secret' => 'account-secret',
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'access-tok',
                'refresh_token' => 'refresh-tok',
            ]),
            'www.googleapis.com/*' => Http::response([
                'items' => [[
                    'id' => 'UC-CHANNEL-1',
                    'snippet' => ['title' => 'Tech Pulse', 'customUrl' => '@techpulse', 'thumbnails' => ['default' => ['url' => 'http://img/tech']]],
                    'statistics' => ['subscriberCount' => '45200', 'videoCount' => '310'],
                ]],
            ]),
        ]);

        session([
            'youtube_oauth_state' => 'valid-state',
            'youtube_oauth_pkce_verifier' => 'test-code-verifier',
            'youtube_oauth_account_id' => $account->id,
        ]);

        $response = $this->actingAs($user)->get(route('accounts.youtube.callback', [
            'code' => 'auth-code',
            'state' => 'valid-state',
        ]));

        $response->assertOk();

        // The token exchange must use the account's own client ID + secret,
        // not the channel-level ones.
        Http::assertSent(function ($request) {
            return $request->url() === 'https://oauth2.googleapis.com/token'
                && $request->data()['client_id'] === 'account-client'
                && $request->data()['client_secret'] === 'account-secret';
        });
    }

    public function test_google_callback_surfaces_youtube_api_not_enabled(): void
    {
        [$user] = $this->createUserWithChannel();
        config([
            'services.google.client_id' => 'client-123',
            'services.google.client_secret' => 'secret-123',
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'access-tok',
                'refresh_token' => 'refresh-tok',
            ]),
            'www.googleapis.com/*' => Http::response([
                'error' => [
                    'code' => 403,
                    'message' => 'Access Not Configured. YouTube Data API v3 has not been used in project before or it is disabled.',
                    'errors' => [
                        ['message' => 'Access Not Configured...', 'domain' => 'usageLimits', 'reason' => 'accessNotConfigured'],
                    ],
                ],
            ], 403),
        ]);

        session([
            'youtube_oauth_state' => 'valid-state',
            'youtube_oauth_pkce_verifier' => 'test-code-verifier',
        ]);

        $response = $this->actingAs($user)->get(route('accounts.youtube.callback', [
            'code' => 'auth-code',
            'state' => 'valid-state',
        ]));

        $response->assertRedirect(route('accounts.index'));
        $this->assertStringContainsString('YouTube Data API v3 is not enabled', session('error'));
        $this->assertStringContainsString('console.cloud.google.com', session('error'));
    }

    public function test_google_callback_surfaces_unknown_google_error(): void
    {
        [$user] = $this->createUserWithChannel();
        config([
            'services.google.client_id' => 'client-123',
            'services.google.client_secret' => 'secret-123',
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'access-tok',
                'refresh_token' => 'refresh-tok',
            ]),
            'www.googleapis.com/*' => Http::response([
                'error' => [
                    'code' => 500,
                    'message' => 'Backend Error',
                    'errors' => [['message' => 'Backend Error', 'domain' => 'global', 'reason' => 'backendError']],
                ],
            ], 500),
        ]);

        session([
            'youtube_oauth_state' => 'valid-state',
            'youtube_oauth_pkce_verifier' => 'test-code-verifier',
        ]);

        $response = $this->actingAs($user)->get(route('accounts.youtube.callback', [
            'code' => 'auth-code',
            'state' => 'valid-state',
        ]));

        $response->assertRedirect(route('accounts.index'));
        $this->assertStringContainsString('Google said: Backend Error', session('error'));
    }

    public function test_google_callback_surfaces_token_exchange_error(): void
    {
        [$user] = $this->createUserWithChannel();
        config([
            'services.google.client_id' => 'client-123',
            'services.google.client_secret' => 'secret-123',
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'error' => 'invalid_grant',
                'error_description' => 'Bad Request',
            ], 400),
        ]);

        session([
            'youtube_oauth_state' => 'valid-state',
            'youtube_oauth_pkce_verifier' => 'test-code-verifier',
        ]);

        $response = $this->actingAs($user)->get(route('accounts.youtube.callback', [
            'code' => 'auth-code',
            'state' => 'valid-state',
        ]));

        $response->assertRedirect(route('accounts.index'));
        $this->assertStringContainsString('invalid_grant', session('error'));
        $this->assertStringContainsString('try connecting again', session('error'));
    }

    public function test_google_callback_surfaces_redirect_uri_mismatch(): void
    {
        [$user] = $this->createUserWithChannel();
        config([
            'services.google.client_id' => 'client-123',
            'services.google.client_secret' => 'secret-123',
            'services.google.redirect' => 'http://localhost:8000/accounts/youtube/callback',
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'error' => 'redirect_uri_mismatch',
                'error_description' => 'Bad Request',
            ], 400),
        ]);

        session([
            'youtube_oauth_state' => 'valid-state',
            'youtube_oauth_pkce_verifier' => 'test-code-verifier',
        ]);

        $response = $this->actingAs($user)->get(route('accounts.youtube.callback', [
            'code' => 'auth-code',
            'state' => 'valid-state',
        ]));

        $response->assertRedirect(route('accounts.index'));
        $this->assertStringContainsString('Authorized redirect URIs', session('error'));
        $this->assertStringContainsString('http://localhost:8000/accounts/youtube/callback', session('error'));
    }

    public function test_popup_callback_failure_redirects_to_popup_error_page(): void
    {
        [$user] = $this->createUserWithChannel();
        config([
            'services.google.client_id' => 'client-123',
            'services.google.client_secret' => 'secret-123',
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'access-tok',
                'refresh_token' => 'refresh-tok',
            ]),
            'www.googleapis.com/*' => Http::response([
                'error' => [
                    'code' => 403,
                    'message' => 'Access Not Configured. YouTube Data API v3 has not been used in project before or it is disabled.',
                    'errors' => [
                        ['message' => 'Access Not Configured...', 'domain' => 'usageLimits', 'reason' => 'accessNotConfigured'],
                    ],
                ],
            ], 403),
        ]);

        session([
            'youtube_oauth_state' => 'valid-state',
            'youtube_oauth_pkce_verifier' => 'test-code-verifier',
            'youtube_oauth_popup' => true,
        ]);

        $response = $this->actingAs($user)->get(route('accounts.youtube.callback', [
            'code' => 'auth-code',
            'state' => 'valid-state',
        ]));

        // Popup flow: the failure must render inside the popup, not navigate
        // the popup to the full accounts page.
        $response->assertRedirect(route('accounts.popup.error'));
        $this->assertStringContainsString('YouTube Data API v3 is not enabled', session('error'));
    }

    public function test_popup_error_page_renders_message_and_retry(): void
    {
        [$user] = $this->createUserWithChannel();
        config([
            'services.google.client_id' => 'client-123',
            'services.google.client_secret' => 'secret-123',
        ]);

        $response = $this->actingAs($user)
            ->withSession(['error' => 'The YouTube Data API v3 is not enabled for your Google Cloud project.'])
            ->get(route('accounts.popup.error'));

        $response->assertOk();
        $response->assertSee('The YouTube Data API v3 is not enabled for your Google Cloud project.', false);
        $response->assertSee('Couldn\'t connect YouTube', false);
        $response->assertSee('Try Again', false);
        $response->assertSee(route('accounts.youtube.connect', ['popup' => 1]), false);
        $response->assertSee('js/popup-error.js', false);
        // Strict CSP: no inline scripts on the popup error page either.
        $response->assertDontSee('<script>');
    }

    public function test_popup_error_retry_keeps_reconnect_account(): void
    {
        [$user, $channel] = $this->createUserWithChannel();
        $account = SocialAccount::create([
            'channel_id' => $channel->id,
            'platform' => 'youtube',
            'account_name' => 'Tech Pulse Shorts',
            'status' => 'connected',
        ]);

        $response = $this->actingAs($user)
            ->withSession(['youtube_oauth_account_id' => $account->id, 'error' => 'Something failed.'])
            ->get(route('accounts.popup.error'));

        $response->assertOk();
        // oauthPopupRetryUrl() builds popup first, then account_id.
        $retryUrl = route('accounts.youtube.connect', ['popup' => 1, 'account_id' => $account->id]);
        $response->assertSee(htmlspecialchars($retryUrl, ENT_QUOTES), false);
    }

    public function test_popup_select_expired_session_redirects_to_popup_error(): void
    {
        [$user] = $this->createUserWithChannel();

        session(['youtube_oauth_popup' => true]);

        $response = $this->actingAs($user)->post(route('accounts.youtube.select'), [
            'channel_id' => 'UC-ONE',
        ]);

        $response->assertRedirect(route('accounts.popup.error'));
        $this->assertStringContainsString('Google session expired', session('error'));
    }

    public function test_popup_google_redirect_not_configured_redirects_to_popup_error(): void
    {
        [$user] = $this->createUserWithChannel();
        config(['services.google.client_id' => null, 'services.google.client_secret' => null]);

        $response = $this->actingAs($user)->get(route('accounts.youtube.connect', ['popup' => 1]));

        // Retry URL is carried along so the popup can restart the same flow.
        $this->assertTrue(str_starts_with($response->headers->get('Location'), route('accounts.popup.error')));
        $this->assertStringContainsString('retry='.urlencode(route('accounts.youtube.connect', ['popup' => 1])), $response->headers->get('Location'));
        $this->assertStringContainsString('Google OAuth is not configured yet', session('error'));
    }

    public function test_accounts_page_shows_oauth_error_from_popup(): void
    {
        [$user] = $this->createUserWithChannel();

        $response = $this->actingAs($user)->get(route('accounts.index', [
            'oauth_error' => 'Could not fetch your YouTube channels — the YouTube Data API v3 is not enabled.',
        ]));

        $response->assertOk();
        $response->assertSee('Could not fetch your YouTube channels — the YouTube Data API v3 is not enabled.', false);
    }

    public function test_selecting_channel_creates_connected_youtube_account(): void
    {
        [$user, $channel] = $this->createUserWithChannel();

        session(['youtube_oauth' => [
            'access_token' => 'access-tok',
            'refresh_token' => 'refresh-tok',
            'channels' => [
                ['id' => 'UC-ONE', 'title' => 'Tech Pulse', 'custom_url' => '@techpulse', 'thumbnail' => null, 'subscribers' => 45200, 'video_count' => 310],
                ['id' => 'UC-TWO', 'title' => 'Gaming Zone', 'custom_url' => '@gamingzone', 'thumbnail' => null, 'subscribers' => 1200, 'video_count' => 88],
            ],
        ]]);

        $response = $this->actingAs($user)->post(route('accounts.youtube.select'), [
            'channel_id' => 'UC-TWO',
        ]);

        $response->assertRedirect(route('accounts.index'));

        $account = SocialAccount::where('channel_id', $channel->id)->where('platform', 'youtube')->first();
        $this->assertNotNull($account);
        $this->assertSame('Gaming Zone', $account->account_name);
        $this->assertSame('@gamingzone', $account->handle);
        $this->assertSame(1200, $account->follower_count);
        $this->assertSame('connected', $account->status);
        $this->assertSame('access-tok', $account->credentials['access_token']);
        $this->assertSame('UC-TWO', $account->credentials['youtube_channel_id']);
        $this->assertNull(session('youtube_oauth'));
    }

    public function test_selecting_channel_requires_valid_selection(): void
    {
        [$user] = $this->createUserWithChannel();

        session(['youtube_oauth' => [
            'access_token' => 'access-tok',
            'refresh_token' => null,
            'channels' => [
                ['id' => 'UC-ONE', 'title' => 'Tech Pulse', 'custom_url' => '@techpulse', 'thumbnail' => null, 'subscribers' => 1, 'video_count' => 1],
            ],
        ]]);

        $response = $this->actingAs($user)->post(route('accounts.youtube.select'), [
            'channel_id' => 'UC-UNKNOWN',
        ]);

        $response->assertSessionHasErrors('channel_id');
        $this->assertDatabaseCount('social_accounts', 0);
    }
}
