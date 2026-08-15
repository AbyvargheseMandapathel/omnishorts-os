<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\GeminiVideoAnalyzer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class SettingsController extends Controller
{
    public function index()
    {
        $analyzer = app(GeminiVideoAnalyzer::class);

        return view('settings.index', [
            'geminiEnabled' => $analyzer->enabled(),
            'geminiModel' => $analyzer->model(),
            'geminiHasApiKey' => filled(Setting::get('gemini.api_key')),
            'geminiModels' => GeminiVideoAnalyzer::MODELS,
        ]);
    }

    public function saveGemini(Request $request)
    {
        $validated = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            // Free-form model name — users type any Gemini model (incl. previews).
            'model' => ['required', 'string', 'max:120'],
            'api_key' => ['nullable', 'string', 'max:500'],
            'remove_api_key' => ['nullable', 'boolean'],
        ]);

        // The key is never echoed back: blank keeps the saved one unless the
        // user explicitly checks "remove".
        $apiKey = $validated['api_key'] ?? null;
        if (filled($apiKey)) {
            Setting::set('gemini.api_key', Crypt::encryptString($apiKey));
        } elseif ($request->boolean('remove_api_key')) {
            Setting::forget('gemini.api_key');
        }

        Setting::set('gemini.model', $validated['model']);
        Setting::set('gemini.enabled', $request->boolean('enabled') ? '1' : '0');

        return back()->with('success', 'Gemini AI settings saved.');
    }

    public function testGemini()
    {
        return response()->json(app(GeminiVideoAnalyzer::class)->testConnection());
    }
}
