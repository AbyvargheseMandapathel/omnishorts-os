<?php

namespace App\Http\Controllers;

use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\JWK;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class AuthController extends Controller
{
    public function showLogin()
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }
        return view('auth.login');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'You have been logged out.');
    }

    /**
     * Verify a Google Identity Services credential (JWT id_token) posted from
     * the browser. Client-ID-only: tokeninfo validates the token with just the
     * client ID — no client secret needed.
     */
    public function handleGoogleCredential(Request $request)
    {
        $clientId = config('services.google.client_id');
        $credential = $request->input('credential');

        if (!$clientId) {
            return redirect()->route('login')->withErrors([
                'google' => 'Google sign-in is not configured yet. Add GOOGLE_CLIENT_ID to your .env file.',
            ]);
        }

        if (!$credential) {
            return redirect()->route('login')->with('error', 'Google sign-in failed. Please try again.');
        }

        // Verify the id_token locally with Google's published signing keys.
        // No client secret and no tokeninfo round-trip or Google-side rate limit.
        $info = $this->decodeGoogleIdToken($credential);

        if (!$info) {
            return redirect()->route('login')->with('error', 'Could not verify your Google sign-in. Please try again.');
        }

        // Signature + exp are validated by JWT::decode; audience and issuer
        // must be pinned so a token minted for another client cannot sign in.
        if (($info['aud'] ?? null) !== $clientId) {
            abort(419, 'Invalid token audience.');
        }

        if (!in_array($info['iss'] ?? '', ['https://accounts.google.com', 'accounts.google.com'], true)) {
            abort(419, 'Invalid token issuer.');
        }

        $googleId = $info['sub'] ?? null;
        $email = $info['email'] ?? null;

        if (!$googleId || !$email) {
            return redirect()->route('login')->with('error', 'Google did not return a profile email for your account.');
        }

        $user = User::where('google_id', $googleId)->first()
            ?? User::where('email', $email)->first();

        if (!$user) {
            $user = User::create([
                'name' => $info['name'] ?? $email,
                'email' => $email,
                'google_id' => $googleId,
                'password' => null,
                'avatar' => $info['picture'] ?? null,
            ]);
        } else {
            // Refresh profile data on every sign-in (and link Google on first).
            $user->update([
                'google_id' => $user->google_id ?: $googleId,
                'avatar' => $info['picture'] ?? $user->avatar,
            ]);
        }

        Auth::login($user);
        $request->session()->regenerate();

        // Existing account (with a channel) -> dashboard. New account -> onboarding.
        if ($user->channels()->count() === 0) {
            return redirect()->route('onboarding.welcome');
        }

        $firstChannel = $user->channels()->first();
        session(['active_channel_id' => $firstChannel->id]);

        return redirect()->route('dashboard');
    }

    /**
     * OAuth config diagnostics. Client ID and redirect URI are public data
     * (the client ID ships in the login page HTML), so this leaks nothing;
     * the secret is only reported as a boolean, never echoed.
     */
    public function googleHealth()
    {
        $url = parse_url((string) config('app.url'));
        $origin = ($url['scheme'] ?? 'http') . '://' . ($url['host'] ?? 'localhost');
        if (isset($url['port'])) {
            $origin .= ':' . $url['port'];
        }

        return response()->json([
            'ok' => true,
            'service' => 'google-oauth',
            'app_url' => config('app.url'),
            'js_origin' => $origin,
            'client_id' => config('services.google.client_id'),
            'client_id_configured' => (bool) config('services.google.client_id'),
            'client_secret_configured' => (bool) config('services.google.client_secret'),
            'youtube_redirect_uri' => config('services.google.redirect'),
            'signin_uri' => route('auth.google.callback'),
            'config_cached' => app()->configurationIsCached(),
        ]);
    }

    /**
     * Verify a Google-issued JWT against Google's JWKS (RS256). Keys are
     * cached for 12h and refetched once on rotation. Returns null on failure.
     */
    private function decodeGoogleIdToken(string $credential): ?array
    {
        $jwks = Cache::get('google_jwks');

        if ($jwks) {
            try {
                return (array) JWT::decode($credential, JWK::parseKeySet($jwks));
            } catch (\Throwable $e) {
                // Key rotation or stale cache — refetch below.
            }
        }

        try {
            $response = Http::get('https://www.googleapis.com/oauth2/v3/certs');
            if ($response->failed()) {
                return null;
            }

            $jwks = $response->json();
            Cache::put('google_jwks', $jwks, now()->addHours(12));

            return (array) JWT::decode($credential, JWK::parseKeySet($jwks));
        } catch (\Throwable $e) {
            // Network/SSL failure — fail closed to a friendly message.
            return null;
        }
    }
}
