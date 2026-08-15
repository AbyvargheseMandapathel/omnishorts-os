<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome to OmniShorts | Setup Wizard</title>
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
            max-width: 640px;
            background: var(--bg-card);
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-xl);
            padding: 40px;
            text-align: center;
            box-shadow: var(--shadow-xl);
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
            
            <h1 style="font-size: 2rem; font-weight: 800; letter-spacing: -0.02em; margin-bottom: 12px;">
                Welcome, {{ auth()->user()->name }}! 🚀
            </h1>
            <p style="color: var(--text-muted); font-size: 1.05rem; line-height: 1.6; max-width: 480px; margin: 0 auto 32px;">
                Name your channel, then connect your YouTube with Google. Takes under a minute.
            </p>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 36px; text-align: left;">
                <div style="background: rgba(255,255,255,0.03); border: 1px solid var(--border-subtle); border-radius: var(--radius-md); padding: 16px;">
                    <div style="font-weight: 700; color: var(--primary); font-size: 0.85rem; margin-bottom: 4px;">Step 1</div>
                    <div style="font-size: 0.95rem; font-weight: 600;">Channel Info</div>
                    <div style="font-size: 0.78rem; color: var(--text-dim); margin-top: 2px;">Name, niche & handle</div>
                </div>

                <div style="background: rgba(255,255,255,0.03); border: 1px solid var(--border-subtle); border-radius: var(--radius-md); padding: 16px;">
                    <div style="font-weight: 700; color: var(--secondary); font-size: 0.85rem; margin-bottom: 4px;">Step 2</div>
                    <div style="font-size: 0.95rem; font-weight: 600;">Connect YouTube</div>
                    <div style="font-size: 0.78rem; color: var(--text-dim); margin-top: 2px;">Google OAuth — pick your channel</div>
                </div>
            </div>

            <a href="{{ route('onboarding.step1') }}" class="btn btn-primary btn-lg" style="width: 100%; font-size: 1.05rem;">
                Configure First Channel →
            </a>
        </div>
    </div>
</body>
</html>
