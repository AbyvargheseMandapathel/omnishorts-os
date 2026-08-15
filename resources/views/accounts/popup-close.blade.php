<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Connected — OmniShorts</title>
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
        .wrap { max-width: 320px; padding: 24px; }
        .mark {
            width: 64px; height: 64px; margin: 0 auto 18px;
            border-radius: 50%; background: #fff; color: #000;
            display: flex; align-items: center; justify-content: center;
            box-shadow: 0 0 24px rgba(255,255,255,0.35);
        }
        h1 { font-size: 1.15rem; font-weight: 700; margin-bottom: 8px; }
        p { font-size: 0.85rem; color: #8e8e8e; line-height: 1.5; }
        .dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: #34d399; margin-right: 6px; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="mark">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="currentColor"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg>
        </div>
        <h1>YouTube connected!</h1>
        <p><span class="dot"></span>{{ session('success') ?? 'This window will close automatically.' }}</p>
    </div>
    <script src="{{ asset('js/popup-close.js') }}"></script>
</body>
</html>
