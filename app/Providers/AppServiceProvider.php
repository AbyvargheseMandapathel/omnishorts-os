<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // SQLite + concurrent writer (scheduler cron + web requests): enable
        // WAL and give writers time to wait instead of instantly failing with
        // "database is locked".
        if (config('database.default') === 'sqlite') {
            try {
                DB::statement('PRAGMA journal_mode = WAL');
                DB::statement('PRAGMA busy_timeout = 10000');
            } catch (\Throwable) {
                // Non-sqlite or unsupported environment — nothing to tune.
            }
        }

        // Some Windows PHP builds have no CA bundle configured, so outbound
        // HTTPS (Google JWKS, YouTube OAuth, token refresh) fails with
        // "unable to get local issuer certificate". Bundle a CA store in the
        // project and point the HTTP client at it when present.
        $caBundle = storage_path('cacert.pem');
        if (is_file($caBundle)) {
            Http::globalOptions(['verify' => $caBundle]);
        }

        // Brute-force guard: email/password login (kept only for legacy users).
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));

        // Google Identity Services credential endpoint: every hit triggers a
        // server-side tokeninfo call, so keep it tight.
        RateLimiter::for('google-auth', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
    }
}
