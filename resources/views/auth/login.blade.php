<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In | OmniShorts OS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <style>
        .auth-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: radial-gradient(circle at top right, rgba(255, 255, 255, 0.05) 0%, transparent 60%),
                        radial-gradient(circle at bottom left, rgba(236, 72, 153, 0.08) 0%, transparent 60%),
                        var(--bg-app);
        }
        .auth-card {
            width: 100%;
            max-width: 440px;
            background: var(--bg-card);
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-xl);
            padding: 36px;
            box-shadow: var(--shadow-xl);
        }
    </style>
</head>
<body>
    <div class="auth-container">
        <div class="auth-card">
            <div style="text-align: center; margin-bottom: 28px;">
                <div class="sidebar-brand-icon" style="margin: 0 auto 12px; width: 44px; height: 44px;">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <polygon points="5 3 19 12 5 21 5 3"></polygon>
                    </svg>
                </div>
                <h2 style="font-size: 1.5rem; font-weight: 800; letter-spacing: -0.02em;">Welcome Back</h2>
                <p style="color: var(--text-muted); font-size: 0.88rem; margin-top: 4px;">Sign in to your OmniShorts Command Center</p>
            </div>

            @if(session('status'))
                <div class="alert alert-success" style="padding: 10px 14px; font-size: 0.85rem;">
                    {{ session('status') }}
                </div>
            @endif

            @if($errors->any())
                <div class="alert alert-error" style="padding: 10px 14px; font-size: 0.85rem;">
                    {{ $errors->first() }}
                </div>
            @endif

            @if(config('services.google.client_id'))
                <div id="g_id_onload"
                     data-client_id="{{ config('services.google.client_id') }}"
                     data-callback="handleGoogleCredential"
                     data-login-action="{{ route('auth.google.callback') }}"
                     data-csrf="{{ csrf_token() }}">
                </div>
                <div class="g_id_signin"
                     data-type="standard"
                     data-shape="pill"
                     data-theme="outline"
                     data-text="continue_with"
                     data-size="large"
                     data-width="340"
                     style="display: flex; justify-content: center;">
                </div>
                <p style="text-align: center; color: var(--text-dim); font-size: 0.78rem; margin-top: 14px;">
                    Sign in securely with your Google account. New here? Your account is created automatically — no password needed.
                </p>
                <script src="{{ asset('js/gis.js') }}"></script>
                <script src="https://accounts.google.com/gsi/client" async defer></script>
            @else
                <div class="alert alert-error" style="padding: 10px 14px; font-size: 0.85rem;">
                    Google sign-in is not configured yet. Add <code>GOOGLE_CLIENT_ID</code> to your <code>.env</code> file.
                </div>
            @endif
        </div>
    </div>
</body>
</html>
