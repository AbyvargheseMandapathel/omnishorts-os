<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OnboardingController extends Controller
{
    public function welcome()
    {
        $user = Auth::user();
        if ($user->channels()->count() > 0) {
            return redirect()->route('dashboard');
        }

        return view('onboarding.welcome');
    }

    public function step1()
    {
        $step1Data = session('onboarding.step1', []);

        return view('onboarding.step1', compact('step1Data'));
    }

    public function saveStep1(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'handle' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        session(['onboarding.step1' => $validated]);

        return redirect()->route('onboarding.finish');
    }

    public function finish()
    {
        $user = Auth::user();
        $step1 = session('onboarding.step1');

        // First pass through: session data present -> create the channel once.
        if ($step1) {
            // Create Channel
            $channel = $user->channels()->create([
                'name' => $step1['name'],
                'handle' => ltrim($step1['handle'], '@'),
                'category' => $step1['category'],
                'description' => $step1['description'] ?? null,
                'status' => 'active',
                'profile_image' => $user->avatar,
            ]);

            // Create a starter short so the library isn't empty. No fabricated
            // metrics — duration and virality stay unknown ("—") until a real
            // file / Gemini analysis exists.
            Video::create([
                'channel_id' => $channel->id,
                'title' => 'Your First Reel — Ready to Schedule',
                'description' => 'Upload your own edited reels from the Content Library, or import a whole bundle at once.',
                'status' => 'ready',
                'ai_data' => [
                    'hook' => 'Wait until you see what these reels can do for your channel!',
                    'caption' => 'Drop a comment if you want more of these every day.',
                    'hashtags' => '#shorts #viral #content #growth #youtube',
                ],
            ]);

            session()->forget('onboarding');
            session(['active_channel_id' => $channel->id]);
        } elseif ($user->channels()->count() === 0) {
            // No session data and nothing created yet — restart onboarding.
            return redirect()->route('onboarding.step1');
        }

        $channel = $user->currentChannel();

        // The YouTube popup flow reloads this page once a channel is picked.
        // At that point the account is connected — hand off to the dashboard.
        if ($channel->socialAccounts()->where('platform', 'youtube')->exists()) {
            return redirect()->route('dashboard')->with('success', 'YouTube channel connected — your scheduled reels will go live there automatically.');
        }

        // Final onboarding step: connect the first YouTube channel.
        return view('onboarding.finish', compact('channel'));
    }
}
