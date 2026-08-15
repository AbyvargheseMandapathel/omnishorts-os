<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Step 1: Channel Info | OmniShorts</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <style>
        .wizard-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: var(--bg-app);
        }
        .wizard-card {
            width: 100%;
            max-width: 580px;
            background: var(--bg-card);
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-xl);
            padding: 36px;
            box-shadow: var(--shadow-xl);
        }
    </style>
</head>
<body>
    <div class="wizard-container">
        <div class="wizard-card">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px;">
                <span style="font-size: 0.85rem; font-weight: 700; color: var(--primary); text-transform: uppercase;">Step 1 of 1</span>
                <span style="font-size: 0.85rem; color: var(--text-dim);">Channel Basics</span>
            </div>

            <h2 style="font-size: 1.6rem; font-weight: 800; letter-spacing: -0.02em; margin-bottom: 8px;">
                What is your Channel Name?
            </h2>
            <p style="color: var(--text-muted); font-size: 0.92rem; margin-bottom: 28px;">
                We pre-filled this from your Google profile — tweak it and pick your niche.
            </p>

            @if($errors->any())
                <div class="alert alert-error">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('onboarding.step1') }}">
                @csrf
                <div class="form-group">
                    <label class="form-label">Channel / Brand Name</label>
                    <input type="text" name="name" class="form-input" value="{{ old('name', $step1Data['name'] ?? auth()->user()->name) }}" placeholder="e.g. AI Blueprint, Daily Stoic" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Main Social Handle</label>
                    <input type="text" name="handle" class="form-input" value="{{ old('handle', $step1Data['handle'] ?? \Illuminate\Support\Str::before(auth()->user()->email, '@')) }}" placeholder="@yourhandle" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Primary Category / Niche</label>
                    <select name="category" class="form-select">
                        <option value="Technology & AI" {{ ($step1Data['category'] ?? '') == 'Technology & AI' ? 'selected' : '' }}>Technology & AI</option>
                        <option value="Health & Fitness" {{ ($step1Data['category'] ?? '') == 'Health & Fitness' ? 'selected' : '' }}>Health & Fitness</option>
                        <option value="Business & Finance" {{ ($step1Data['category'] ?? '') == 'Business & Finance' ? 'selected' : '' }}>Business & Finance</option>
                        <option value="Motivation & Self-Growth" {{ ($step1Data['category'] ?? '') == 'Motivation & Self-Growth' ? 'selected' : '' }}>Motivation & Self-Growth</option>
                        <option value="Entertainment & Gaming" {{ ($step1Data['category'] ?? '') == 'Entertainment & Gaming' ? 'selected' : '' }}>Entertainment & Gaming</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Channel Mission / Description</label>
                    <textarea name="description" class="form-textarea" placeholder="Daily 45-second breakdowns of groundbreaking AI tools and workflow hacks.">{{ old('description', $step1Data['description'] ?? 'Daily short-form video breakdowns of cutting-edge technology and productivity.') }}</textarea>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 32px;">
                    <button type="submit" class="btn btn-primary btn-lg" style="width: 100%;">
                        Continue to Brand Presets →
                    </button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
