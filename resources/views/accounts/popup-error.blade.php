<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Connection failed — OmniShorts</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            background: #000;
            color: #fff;
            font-family: "Inter", "Segoe UI", system-ui, sans-serif;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            -webkit-font-smoothing: antialiased;
        }
        .wrap { max-width: 360px; padding: 24px; }
        .mark {
            width: 64px; height: 64px; margin: 0 auto 18px;
            border-radius: 50%; background: rgba(248, 113, 113, 0.15); color: #f87171;
            border: 1px solid rgba(248, 113, 113, 0.35);
            display: flex; align-items: center; justify-content: center;
        }
        h1 { font-size: 1.15rem; font-weight: 700; margin-bottom: 8px; }
        p.message { font-size: 0.85rem; color: #b9b9b9; line-height: 1.55; margin-bottom: 20px; }
        .actions { display: flex; gap: 10px; justify-content: center; }
        .btn {
            font-family: inherit; font-size: 0.85rem; font-weight: 600;
            padding: 10px 18px; border-radius: 999px; border: none; cursor: pointer;
        }
        .btn-primary { background: #fff; color: #000; }
        .btn-secondary { background: transparent; color: #fff; border: 1px solid rgba(255, 255, 255, 0.25); }
        .hint { font-size: 0.72rem; color: #6f6f6f; margin-top: 14px; }
    </style>
</head>
<body data-oauth-error="{{ session('error') ?? 'Google connection failed. Please try again.' }}">
    <div class="wrap">
        <div class="mark">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"></circle>
                <line x1="12" x2="12" y1="8" y2="12"></line>
                <line x1="12" x2="12.01" y1="16" y2="16"></line>
            </svg>
        </div>
        <h1>Couldn't connect YouTube</h1>
        <p class="message">{{ session('error') ?? 'Google connection failed. Please try again.' }}</p>
        <div class="actions">
            <button type="button" class="btn btn-primary" id="retryBtn" data-retry-url="{{ $retryUrl }}">Try Again</button>
            <button type="button" class="btn btn-secondary" id="closeBtn">Close</button>
        </div>
        <div class="hint">This window will close automatically.</div>
    </div>
    <script src="{{ asset('js/popup-error.js') }}"></script>
</body>
</html>
