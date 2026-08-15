<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ChannelController extends Controller
{
    public function switch(Channel $channel)
    {
        $user = Auth::user();

        if ($channel->user_id !== $user->id) {
            abort(403, 'Unauthorized action.');
        }

        session(['active_channel_id' => $channel->id]);

        return back()->with('success', "Switched active channel to '{$channel->name}'");
    }

    public function store(Request $request)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'handle' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $channel = $user->channels()->create([
            'name' => $validated['name'],
            'handle' => $validated['handle'] ? ltrim($validated['handle'], '@') : null,
            'category' => $validated['category'] ?? 'Tech & Innovation',
            'description' => $validated['description'] ?? null,
            'status' => 'active',
        ]);

        session(['active_channel_id' => $channel->id]);

        return redirect()->route('dashboard')->with('success', "Channel '{$channel->name}' created successfully!");
    }

    public function update(Request $request, Channel $channel)
    {
        $user = Auth::user();
        if ($channel->user_id !== $user->id) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'handle' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $channel->update($validated);

        return back()->with('success', 'Channel settings updated successfully.');
    }

    public function updateSchedule(Request $request, Channel $channel)
    {
        $user = Auth::user();
        if ($channel->user_id !== $user->id) {
            abort(403);
        }

        $validated = $request->validate([
            'posts_per_day' => ['required', 'integer', 'min:1', 'max:10'],
            'post_times' => ['required', 'array', 'min:1', 'max:10'],
            'post_times.*' => ['required', 'date_format:H:i'],
        ]);

        $channel->update([
            'posts_per_day' => (int) $validated['posts_per_day'],
            'post_times' => array_values(array_slice($validated['post_times'], 0, (int) $validated['posts_per_day'])),
        ]);

        return back()->with('success', 'Posting schedule updated. ' . $channel->posts_per_day . ' upload(s) per day at ' . implode(', ', $channel->postingTimes()) . '.');
    }
}
