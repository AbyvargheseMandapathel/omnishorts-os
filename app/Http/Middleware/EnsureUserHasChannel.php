<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasChannel
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (auth()->check()) {
            $user = auth()->user();
            $channelsCount = $user->channels()->count();

            if ($channelsCount === 0) {
                if (!$request->is('onboarding*') && !$request->is('logout')) {
                    return redirect()->route('onboarding.welcome');
                }
            } else {
                $activeChannelId = session('active_channel_id');
                $hasChannel = $activeChannelId ? $user->channels()->where('id', $activeChannelId)->exists() : false;

                if (!$hasChannel) {
                    $firstChannel = $user->channels()->first();
                    if ($firstChannel) {
                        session(['active_channel_id' => $firstChannel->id]);
                    }
                }
            }
        }

        return $next($request);
    }
}
