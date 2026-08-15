<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SecurityAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_google_credential_endpoint_is_rate_limited(): void
    {
        config(['services.google.client_id' => 'client-123']);

        Http::fake([
            'www.googleapis.com/oauth2/v3/certs' => Http::response(['keys' => []]),
        ]);

        // 10 requests allowed per minute per IP; the 11th must be throttled.
        for ($i = 0; $i < 10; $i++) {
            $this->post(route('auth.google.callback'), ['credential' => 'jwt-'.$i])->assertStatus(302);
        }

        $this->post(route('auth.google.callback'), ['credential' => 'jwt-11'])->assertStatus(429);
    }

    public function test_app_follows_ist_timezone(): void
    {
        // Scheduling is displayed in Indian Standard Time unless explicitly overridden.
        $this->assertSame('Asia/Kolkata', config('app.timezone'));
        $this->assertSame('+05:30', now()->format('P'));
    }

    public function test_legacy_password_login_route_is_removed(): void
    {
        $this->post('/login', [
            'email' => 'demo@example.com',
            'password' => 'password',
        ])->assertStatus(405);
    }

    public function test_security_headers_present_on_pages(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        // Strict CSP: scripts from self + Google only, no 'unsafe-inline'.
        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("script-src 'self' https://accounts.google.com", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("media-src 'self' https:", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $csp);
    }

    public function test_pages_contain_no_inline_scripts_or_handlers(): void
    {
        $user = User::factory()->create();
        Channel::create(['user_id' => $user->id, 'name' => 'Test Channel']);

        $login = $this->get(route('login'))->assertOk()->getContent();
        $dashboard = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();

        // External scripts only — no inline <script> blocks.
        $this->assertStringNotContainsString('<script>', $login);
        $this->assertStringNotContainsString('<script>', $dashboard);
        // No inline event handlers (blocked by strict CSP without unsafe-inline).
        $this->assertDoesNotMatchRegularExpression('/\son(click|change|input|submit|mouseover|mouseout)=/', $login);
        $this->assertDoesNotMatchRegularExpression('/\son(click|change|input|submit|mouseover|mouseout)=/', $dashboard);
    }

    public function test_authenticated_core_routes_are_rate_limited(): void
    {
        $user = User::factory()->create();
        Channel::create(['user_id' => $user->id, 'name' => 'Test Channel']);

        // 60 requests per minute per IP on the authenticated core group.
        for ($i = 0; $i < 60; $i++) {
            $this->actingAs($user)->get(route('dashboard'))->assertOk();
        }

        $this->actingAs($user)->get(route('dashboard'))->assertStatus(429);
    }

    public function test_cross_user_channel_access_is_forbidden(): void
    {
        $owner = User::factory()->create();
        $channel = Channel::create(['user_id' => $owner->id, 'name' => 'Owner Channel']);
        $intruder = User::factory()->create();
        // Give the intruder their own channel so channel.required lets the
        // request through to the controller's ownership check.
        Channel::create(['user_id' => $intruder->id, 'name' => 'Intruder Channel']);

        // Intruder tries to switch to the owner's channel.
        $this->actingAs($intruder)->post(route('channels.switch', $channel))->assertStatus(403);

        // Intruder tries to update the owner's channel schedule.
        $this->actingAs($intruder)->put(route('channels.update', $channel), [
            'name' => 'Hacked',
        ])->assertStatus(403);
    }
}
