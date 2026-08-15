<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connect YouTube | OmniShorts</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <style>
        .wizard-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: radial-gradient(circle at center, rgba(255, 255, 255, 0.04) 0%, var(--bg-app) 70%);
        }
        .wizard-card {
            width: 100%;
            max-width: 560px;
            background: var(--bg-card);
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-xl);
            padding: 40px;
            text-align: center;
            box-shadow: var(--shadow-xl);
        }
        .step-check {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(52, 211, 153, 0.1);
            border: 1px solid rgba(52, 211, 153, 0.3);
            color: #34d399;
            font-size: 0.8rem;
            font-weight: 700;
            padding: 5px 12px;
            border-radius: 999px;
            margin-bottom: 18px;
        }
    </style>
</head>
<body>
    <div class="wizard-container">
        <div class="wizard-card">
            <div class="sidebar-brand-icon" style="width: 56px; height: 56px; margin: 0 auto 20px;">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <polygon points="5 3 19 12 5 21 5 3"></polygon>
                </svg>
            </div>

            <div class="step-check">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                Channel created
            </div>

            <h1 style="font-size: 1.9rem; font-weight: 800; letter-spacing: -0.02em; margin-bottom: 12px;">
                Connect your first YouTube channel
            </h1>
            <p style="color: var(--text-muted); font-size: 1rem; line-height: 1.6; max-width: 440px; margin: 0 auto 30px;">
                Sign in with Google, pick your channel, grant permission — and your scheduled reels will publish there automatically.
            </p>

            @if($errors->has('google'))
                <div class="alert alert-error" style="margin-bottom: 20px; text-align: left;">
                    <span>{{ $errors->first('google') }}</span>
                </div>
            @endif

            @php
                $creds = $channel->googleOAuthCredentials();
            @endphp
            @if(!$creds['client_id'])
                <div class="alert alert-error" style="margin-bottom: 20px; text-align: left;">
                    <span>Google OAuth is not configured yet. Add your Client ID + Secret on the
                        <a href="{{ route('accounts.index') }}" style="color: var(--accent-rose); font-weight: 700; text-decoration: underline;">YouTube Channels page</a>
                        or set <code style="font-size: 0.75rem;">GOOGLE_CLIENT_ID</code> / <code style="font-size: 0.75rem;">GOOGLE_CLIENT_SECRET</code> in your .env file.</span>
                </div>
            @endif

            <button type="button" class="btn btn-google btn-lg" style="width: 100%; font-size: 1.02rem; padding: 13px 24px;" data-oauth-url="{{ route('accounts.youtube.connect', ['popup' => 1]) }}">
                <svg width="21" height="21" viewBox="0 0 48 48" aria-hidden="true"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>
                <span>Continue with Google</span>
            </button>

            <div style="margin-top: 18px;">
                <a href="{{ route('dashboard') }}" style="font-size: 0.86rem; color: var(--text-dim);">
                    Skip for now — go to dashboard →
                </a>
            </div>
        </div>
    </div>

    <script src="{{ asset('js/app.js') }}"></script>
</body>
</html>
