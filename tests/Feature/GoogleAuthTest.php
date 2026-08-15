<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\SocialAccount;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Generate an RSA keypair, serve its public half as Google's JWKS, and
     * return the private key so tests can mint real RS256 id_tokens.
     */
    private function fakeGoogleJwks(): string
    {
        // Some Windows PHP builds need an explicit openssl.cnf for key generation.
        $cnf = sys_get_temp_dir() . '/omshorts-openssl.cnf';
        if (!is_file($cnf)) {
            file_put_contents($cnf, "[ req ]\ndistinguished_name = dn\n[ dn ]\n");
        }

        $keyPair = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'config' => $cnf,
        ]);
        openssl_pkey_export($keyPair, $privateKeyPem, null, ['config' => $cnf]);

        $details = openssl_pkey_get_details($keyPair);
        $jwks = [
            'keys' => [[
                'kty' => 'RSA',
                'use' => 'sig',
                'alg' => 'RS256',
                'kid' => 'test-key-1',
                'n' => JWT::urlsafeB64Encode($details['rsa']['n']),
                'e' => JWT::urlsafeB64Encode($details['rsa']['e']),
            ]],
        ];

        Http::fake([
            'www.googleapis.com/oauth2/v3/certs' => Http::response($jwks),
        ]);

        return $privateKeyPem;
    }

    private function googleToken(string $privateKey, array $claims): string
    {
        return JWT::encode(
            array_merge([
                'iss' => 'https://accounts.google.com',
                'aud' => 'client-123',
                'iat' => now()->timestamp,
                'exp' => now()->addHour()->timestamp,
            ], $claims),
            $privateKey,
            'RS256',
            'test-key-1'
        );
    }

    public function test_google_health_reports_configuration_without_secret(): void
    {
        config([
            'app.url' => 'http://localhost:8000',
            'services.google.client_id' => 'client-123.apps.googleusercontent.com',
            'services.google.client_secret' => 'GOCSPX-top-secret',
        ]);

        $response = $this->get(route('health.google'));

        $response->assertOk();
        $response->assertJson([
            'js_origin' => 'http://localhost:8000',
            'client_id' => 'client-123.apps.googleusercontent.com',
            'client_id_configured' => true,
            'client_secret_configured' => true,
            'youtube_redirect_uri' => config('services.google.redirect'),
        ]);
        // The secret value itself must never be echoed.
        $response->assertDontSee('GOCSPX-top-secret');
    }

    public function test_google_health_reports_missing_configuration(): void
    {
        config([
            'services.google.client_id' => null,
            'services.google.client_secret' => null,
        ]);

        $response = $this->get(route('health.google'));

        $response->assertOk();
        $response->assertJson([
            'client_id_configured' => false,
            'client_secret_configured' => false,
        ]);
    }

    public function test_login_page_renders_google_sign_in_button(): void
    {
        config(['services.google.client_id' => 'client-123']);

        $response = $this->get(route('login'));

        $response->assertOk();
        $response->assertSee('g_id_onload', false);
        $response->assertSee('data-client_id="client-123"', false);
        $response->assertSee('g_id_signin', false);
    }

    public function test_login_page_shows_config_warning_without_client_id(): void
    {
        config(['services.google.client_id' => null]);

        $response = $this->get(route('login'));

        $response->assertOk();
        $response->assertDontSee('g_id_signin', false);
        $response->assertSee('GOOGLE_CLIENT_ID', false);
    }

    public function test_google_credential_creates_user_and_logs_in(): void
    {
        config([
            'services.google.client_id' => 'client-123',
            'services.google.client_secret' => null,
        ]);

        $privateKey = $this->fakeGoogleJwks();
        $token = $this->googleToken($privateKey, [
            'sub' => 'google-sub-1',
            'email' => 'alex@creatorhub.com',
            'email_verified' => true,
            'name' => 'Alex Morgan',
            'picture' => 'http://img/alex',
        ]);

        $response = $this->post(route('auth.google.callback'), ['credential' => $token]);

        $response->assertRedirect(route('onboarding.welcome'));

        $user = User::where('google_id', 'google-sub-1')->first();
        $this->assertNotNull($user);
        $this->assertSame('alex@creatorhub.com', $user->email);
        $this->assertSame('Alex Morgan', $user->name);
        $this->assertSame('http://img/alex', $user->avatar);
        $this->assertNull($user->password);
        $this->assertAuthenticatedAs($user);
    }

    public function test_google_credential_links_existing_account_by_email(): void
    {
        config([
            'services.google.client_id' => 'client-123',
            'services.google.client_secret' => null,
        ]);

        $existing = User::factory()->create(['email' => 'alex@creatorhub.com']);
        Channel::create(['user_id' => $existing->id, 'name' => 'Tech Pulse']);

        $privateKey = $this->fakeGoogleJwks();
        $token = $this->googleToken($privateKey, [
            'sub' => 'google-sub-9',
            'email' => 'alex@creatorhub.com',
            'name' => 'Alex Morgan',
        ]);

        $response = $this->post(route('auth.google.callback'), ['credential' => $token]);

        $response->assertRedirect(route('dashboard'));
        $this->assertSame('google-sub-9', $existing->fresh()->google_id);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_google_credential_rejects_wrong_audience(): void
    {
        config(['services.google.client_id' => 'client-123']);

        $privateKey = $this->fakeGoogleJwks();
        // Validly signed, but minted for a different client — must be rejected.
        $token = $this->googleToken($privateKey, [
            'aud' => 'someone-elses-client',
            'sub' => 'google-sub-1',
            'email' => 'alex@creatorhub.com',
        ]);

        $response = $this->post(route('auth.google.callback'), ['credential' => $token]);

        $response->assertStatus(419);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_google_credential_rejects_expired_token(): void
    {
        config(['services.google.client_id' => 'client-123']);

        $privateKey = $this->fakeGoogleJwks();
        $token = JWT::encode([
            'iss' => 'https://accounts.google.com',
            'aud' => 'client-123',
            'sub' => 'google-sub-1',
            'email' => 'alex@creatorhub.com',
            'iat' => now()->subHours(3)->timestamp,
            'exp' => now()->subHour()->timestamp,
        ], $privateKey, 'RS256', 'test-key-1');

        $response = $this->post(route('auth.google.callback'), ['credential' => $token]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('error');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_google_credential_rejects_garbage_token(): void
    {
        config(['services.google.client_id' => 'client-123']);
        $this->fakeGoogleJwks();

        $response = $this->post(route('auth.google.callback'), ['credential' => 'not-a-jwt']);

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('error');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_google_credential_requires_configuration(): void
    {
        config(['services.google.client_id' => null]);

        $response = $this->post(route('auth.google.callback'), ['credential' => 'fake-jwt']);

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('google');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_google_credential_requires_credential(): void
    {
        config(['services.google.client_id' => 'client-123']);

        $response = $this->post(route('auth.google.callback'));

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('error');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_onboarding_finish_shows_connect_button_and_creates_channel(): void
    {
        $user = User::factory()->create();
        session(['onboarding.step1' => [
            'name' => 'Tech Pulse',
            'handle' => '@techpulse',
            'category' => 'Technology & AI',
            'description' => null,
        ]]);

        $response = $this->actingAs($user)->get(route('onboarding.finish'));

        $response->assertOk();
        $response->assertSee('Continue with Google', false);
        $response->assertSee(route('accounts.youtube.connect', ['popup' => 1]), false);

        $this->assertDatabaseCount('channels', 1);
        $this->assertSame('Tech Pulse', $user->channels()->first()->name);
        $this->assertSame('techpulse', $user->channels()->first()->handle);
        $this->assertDatabaseCount('videos', 1);
        $this->assertNull(session('onboarding.step1'));
    }

    public function test_onboarding_finish_is_idempotent_on_reload(): void
    {
        $user = User::factory()->create();
        session(['onboarding.step1' => [
            'name' => 'Tech Pulse',
            'handle' => '@techpulse',
            'category' => 'Technology & AI',
            'description' => null,
        ]]);

        $this->actingAs($user)->get(route('onboarding.finish'));

        // Reload simulates the OAuth popup reloading its opener.
        $response = $this->actingAs($user)->get(route('onboarding.finish'));

        $response->assertOk();
        $this->assertDatabaseCount('channels', 1);
        $this->assertDatabaseCount('videos', 1);
    }

    public function test_onboarding_finish_redirects_to_dashboard_once_youtube_connected(): void
    {
        $user = User::factory()->create();
        $channel = Channel::create(['user_id' => $user->id, 'name' => 'Tech Pulse']);
        SocialAccount::create([
            'channel_id' => $channel->id,
            'platform' => 'youtube',
            'account_name' => 'Tech Pulse',
            'status' => 'connected',
        ]);

        $response = $this->actingAs($user)->get(route('onboarding.finish'));

        $response->assertRedirect(route('dashboard'));
        $this->assertDatabaseCount('channels', 1);
    }

    public function test_onboarding_finish_without_session_redirects_to_step1(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('onboarding.finish'));

        $response->assertRedirect(route('onboarding.step1'));
        $this->assertDatabaseCount('channels', 0);
    }

    public function test_onboarding_step1_prefills_from_google_profile(): void
    {
        $user = User::factory()->create([
            'name' => 'Alex Morgan',
            'email' => 'alex@creatorhub.com',
        ]);

        $response = $this->actingAs($user)->get(route('onboarding.step1'));

        $response->assertOk();
        $response->assertSee('value="Alex Morgan"', false);
        $response->assertSee('value="alex"', false);
    }

    public function test_onboarding_finish_copies_google_avatar_to_channel(): void
    {
        $user = User::factory()->create(['avatar' => 'http://img/avatar.png']);
        session(['onboarding.step1' => [
            'name' => 'Tech Pulse',
            'handle' => '@techpulse',
            'category' => 'Technology & AI',
            'description' => null,
        ]]);

        $this->actingAs($user)->get(route('onboarding.finish'));

        $this->assertSame('http://img/avatar.png', $user->channels()->first()->profile_image);
    }
}
