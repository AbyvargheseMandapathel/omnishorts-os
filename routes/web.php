<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\ChannelController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SocialAccountController;
use App\Http\Controllers\VideoController;
use Illuminate\Support\Facades\Route;

// Public routes
Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('dashboard');
    }

    return view('landing');
})->name('home');

// OAuth config health check — exposes the configured client ID / origin and
// booleans only. Never the client secret. Helps diagnose origin_mismatch /
// invalid_client without digging into .env.
Route::get('/health/google', [AuthController::class, 'googleHealth'])->name('health.google');
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');

    // No separate register page — account creation happens through Google sign-in.
    // Keep the named route so landing-page CTAs still work; they land on login.
    Route::get('/register', fn () => redirect()->route('login'))->name('register');

    // Google-only sign in / sign up — Google Identity Services posts a JWT
    // credential here; verified with just the client ID (no client secret).
    Route::post('/auth/google/callback', [AuthController::class, 'handleGoogleCredential'])
        ->middleware('throttle:google-auth')
        ->name('auth.google.callback');
});

Route::post('/logout', [AuthController::class, 'logout'])->name('logout')->middleware('auth');

// Onboarding — create channel, then connect YouTube
Route::middleware(['auth', 'throttle:30,1'])->prefix('onboarding')->name('onboarding.')->group(function () {
    Route::get('/', [OnboardingController::class, 'welcome'])->name('welcome');
    Route::get('/step-1', [OnboardingController::class, 'step1'])->name('step1');
    Route::post('/step-1', [OnboardingController::class, 'saveStep1']);
    Route::get('/finish', [OnboardingController::class, 'finish'])->name('finish');
});

// Authenticated Application Core Routes
Route::middleware(['auth', 'channel.required', 'throttle:60,1'])->group(function () {
    // Channel switching and creation
    Route::post('/channels/switch/{channel}', [ChannelController::class, 'switch'])->name('channels.switch');
    Route::post('/channels', [ChannelController::class, 'store'])->name('channels.store');
    Route::put('/channels/{channel}', [ChannelController::class, 'update'])->name('channels.update');
    Route::put('/channels/{channel}/schedule', [ChannelController::class, 'updateSchedule'])->name('channels.schedule.update');

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::post('/dashboard/cron/run', [DashboardController::class, 'runCron'])->name('dashboard.cron.run');
    Route::post('/dashboard/analytics/refresh', [DashboardController::class, 'refreshAnalytics'])->name('dashboard.analytics.refresh');

    // Videos
    Route::get('/videos', [VideoController::class, 'index'])->name('videos.index');
    Route::get('/videos/upload', [VideoController::class, 'create'])->name('videos.create');
    Route::post('/videos', [VideoController::class, 'store'])->name('videos.store');
    Route::get('/videos/bulk', [VideoController::class, 'bulkCreate'])->name('videos.bulk');
    Route::post('/videos/bulk', [VideoController::class, 'bulkStore'])->name('videos.bulk.store');
    Route::get('/videos/{video}', [VideoController::class, 'show'])->name('videos.show');
    Route::put('/videos/{video}', [VideoController::class, 'update'])->name('videos.update');
    Route::post('/videos/{video}/publish', [VideoController::class, 'publish'])->name('videos.publish');
    Route::post('/videos/{video}/reupload', [VideoController::class, 'reupload'])->name('videos.reupload');
    Route::post('/videos/{video}/analyze', [VideoController::class, 'analyze'])->name('videos.analyze');
    Route::post('/videos/{video}/refresh-stats', [VideoController::class, 'refreshStats'])->name('videos.refresh-stats');
    Route::post('/videos/{video}/progress', [VideoController::class, 'simulateProgress'])->name('videos.progress');
    Route::delete('/videos/{video}', [VideoController::class, 'destroy'])->name('videos.destroy');

    // YouTube Accounts
    Route::get('/accounts', [SocialAccountController::class, 'index'])->name('accounts.index');
    Route::post('/accounts/google-config', [SocialAccountController::class, 'saveGoogleConfig'])->name('accounts.google.config');
    Route::get('/accounts/youtube/connect', [SocialAccountController::class, 'googleRedirect'])->name('accounts.youtube.connect');
    Route::get('/accounts/youtube/callback', [SocialAccountController::class, 'googleCallback'])->name('accounts.youtube.callback');
    Route::post('/accounts/youtube/select', [SocialAccountController::class, 'selectYoutubeChannel'])->name('accounts.youtube.select');
    Route::get('/accounts/youtube/popup-close', [SocialAccountController::class, 'popupClose'])->name('accounts.popup.close');
    Route::get('/accounts/youtube/popup-error', [SocialAccountController::class, 'popupError'])->name('accounts.popup.error');
    Route::put('/accounts/{account}/schedule', [SocialAccountController::class, 'updateSchedule'])->name('accounts.schedule.update');
    Route::delete('/accounts/{account}', [SocialAccountController::class, 'disconnect'])->name('accounts.disconnect');

    // Calendar & Schedule
    Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar.index');
    Route::post('/calendar/publications/{publication}/move', [CalendarController::class, 'move'])->name('calendar.publication.move');
    Route::post('/calendar/schedule', [CalendarController::class, 'schedule'])->name('calendar.schedule');

    // Settings
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::post('/settings/gemini', [SettingsController::class, 'saveGemini'])->name('settings.gemini.save');
    Route::post('/settings/gemini/test', [SettingsController::class, 'testGemini'])->name('settings.gemini.test');
    Route::post('/settings/cron', [SettingsController::class, 'saveCron'])->name('settings.cron.save');
    Route::post('/settings/cron/install', [SettingsController::class, 'installCron'])->name('settings.cron.install');
    Route::post('/settings/cron/uninstall', [SettingsController::class, 'uninstallCron'])->name('settings.cron.uninstall');
    Route::post('/settings/deploy', [SettingsController::class, 'runDeploy'])->name('settings.deploy.run');
});
