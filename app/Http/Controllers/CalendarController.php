<?php

namespace App\Http\Controllers;

use App\Models\Publication;
use App\Models\SocialAccount;
use App\Models\Video;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CalendarController extends Controller
{
    public function index(Request $request)
    {
        $channel = Auth::user()->currentChannel();
        if (! $channel) {
            return redirect()->route('onboarding.welcome');
        }

        $selectedDate = $request->input('date', Carbon::today()->toDateString());
        $currentMonth = Carbon::parse($selectedDate)->startOfMonth();

        $publications = Publication::with(['video', 'socialAccount'])
            ->whereHas('video', function ($q) use ($channel) {
                $q->where('channel_id', $channel->id);
            })
            ->whereHas('socialAccount', function ($q) {
                $q->where('platform', 'youtube');
            })
            ->whereNotNull('scheduled_at')
            ->orderBy('scheduled_at', 'asc')
            ->get();

        $startOfWeek = $currentMonth->copy()->startOfWeek(Carbon::SUNDAY);
        $endOfWeek = $currentMonth->copy()->endOfMonth()->endOfWeek(Carbon::SATURDAY);

        $calendarDays = [];
        $day = $startOfWeek->copy();

        while ($day <= $endOfWeek) {
            $dateStr = $day->toDateString();
            $dayPubs = $publications->filter(function ($pub) use ($dateStr) {
                return $pub->scheduled_at && $pub->scheduled_at->toDateString() === $dateStr;
            });

            $calendarDays[] = [
                'date' => $day->copy(),
                'isCurrentMonth' => $day->month === $currentMonth->month,
                'isToday' => $day->isToday(),
                'publications' => $dayPubs,
            ];

            $day->addDay();
        }

        $upcomingQueue = Publication::with(['video', 'socialAccount'])
            ->whereHas('video', function ($q) use ($channel) {
                $q->where('channel_id', $channel->id);
            })
            ->whereHas('socialAccount', function ($q) {
                $q->where('platform', 'youtube');
            })
            ->where('status', 'scheduled')
            ->orderBy('scheduled_at', 'asc')
            ->take(10)
            ->get();

        $scheduledVideoIds = Publication::whereHas('video', function ($q) use ($channel) {
            $q->where('channel_id', $channel->id);
        })->pluck('video_id');

        $unscheduledVideos = $channel->videos()
            ->where('status', 'ready')
            ->whereNotIn('id', $scheduledVideoIds)
            ->latest()
            ->take(12)
            ->get();

        return view('calendar.index', compact('channel', 'currentMonth', 'calendarDays', 'upcomingQueue', 'selectedDate', 'unscheduledVideos'));
    }

    public function move(Request $request, Publication $publication)
    {
        $channel = Auth::user()->currentChannel();
        if (! $channel || $publication->video->channel_id !== $channel->id) {
            abort(403);
        }

        $validated = $request->validate([
            'date' => ['required', 'date'],
        ]);

        $targetDate = Carbon::parse($validated['date']);
        $defaultTimes = $publication->socialAccount?->postingTimes() ?? $channel->postingTimes();
        $keepTime = $publication->scheduled_at
            ? [$publication->scheduled_at->hour, $publication->scheduled_at->minute]
            : array_map('intval', explode(':', $defaultTimes[0]));

        $publication->update([
            'scheduled_at' => $targetDate->copy()->setHour($keepTime[0])->setMinute($keepTime[1])->setSecond(0),
            'status' => 'scheduled',
        ]);

        if ($request->wantsJson()) {
            return response()->json(['success' => true]);
        }

        return redirect()->route('calendar.index', ['date' => $targetDate->toDateString()])
            ->with('success', 'Post moved to '.$targetDate->format('M d, Y').'.');
    }

    public function schedule(Request $request)
    {
        $channel = Auth::user()->currentChannel();
        if (! $channel) {
            return redirect()->route('onboarding.welcome');
        }

        $validated = $request->validate([
            'video_id' => ['required', 'exists:videos,id'],
            'date' => ['required', 'date'],
        ]);

        $video = Video::where('channel_id', $channel->id)->findOrFail($validated['video_id']);
        $account = $channel->socialAccounts()
            ->where('platform', 'youtube')
            ->where('status', 'connected')
            ->first();

        if (! $account) {
            return response()->json(['success' => false, 'message' => 'Connect a YouTube channel first.'], 422);
        }

        $targetDate = Carbon::parse($validated['date']);
        $slot = $this->firstFreeSlot($account, $targetDate);

        Publication::updateOrCreate(
            [
                'video_id' => $video->id,
                'social_account_id' => $account->id,
            ],
            [
                'custom_title' => $video->title,
                'custom_caption' => $video->ai_data['caption'] ?? null,
                'custom_hashtags' => $video->ai_data['hashtags'] ?? null,
                'scheduled_at' => $slot,
                'status' => 'scheduled',
            ]
        );

        $video->update(['status' => 'scheduled']);

        if ($request->wantsJson()) {
            return response()->json(['success' => true]);
        }

        return redirect()->route('calendar.index', ['date' => $targetDate->toDateString()])
            ->with('success', $video->title.' scheduled for '.$targetDate->format('M d, Y').'.');
    }

    /**
     * First free cron slot for the given YouTube account on the target date —
     * each account keeps its own schedule and its own occupied slots.
     */
    private function firstFreeSlot(SocialAccount $account, Carbon $targetDate): Carbon
    {
        $occupied = Publication::where('social_account_id', $account->id)
            ->where('status', 'scheduled')
            ->whereDate('scheduled_at', $targetDate->toDateString())
            ->pluck('scheduled_at')
            ->map(fn ($at) => $at->format('H:i'))
            ->all();

        foreach ($account->postingTimes() as $time) {
            if (! in_array($time, $occupied, true)) {
                [$h, $m] = array_map('intval', explode(':', $time));

                return $targetDate->copy()->setHour($h)->setMinute($m)->setSecond(0);
            }
        }

        [$h, $m] = array_map('intval', explode(':', $account->postingTimes()[0]));

        return $targetDate->copy()->setHour($h)->setMinute($m)->setSecond(0);
    }
}
