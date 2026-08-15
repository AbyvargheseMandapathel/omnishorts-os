<?php

namespace Database\Seeders;

use App\Models\Channel;
use App\Models\Publication;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Video;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Create Demo User
        $user = User::create([
            'name' => 'Alex Morgan',
            'email' => 'demo@example.com',
            'password' => Hash::make('password'),
        ]);

        // 2. Create Channel 1: TechPulse AI
        $channel1 = Channel::create([
            'user_id' => $user->id,
            'name' => 'TechPulse AI',
            'handle' => 'techpulse.ai',
            'category' => 'Technology & AI',
            'description' => 'Fast-paced short breakdowns of bleeding-edge AI tools and creator workflows.',
            'status' => 'active',
            'posts_per_day' => 2,
            'post_times' => ['12:00', '18:00'],
        ]);

        $yt1 = SocialAccount::create([
            'channel_id' => $channel1->id,
            'platform' => 'youtube',
            'account_name' => 'TechPulse Shorts',
            'handle' => '@techpulseshorts',
            'follower_count' => 84500,
            'status' => 'connected',
            'credentials' => ['youtube_channel_id' => 'UC-demo-techpulse'],
        ]);

        $videosData1 = [
            [
                'title' => '3 Secret AI Websites That Will Save You 10 Hours This Week',
                'duration' => 44,
                'status' => 'published',
                'hook' => 'STOP SCROLLING: These 3 AI tools feel illegal to know.',
                'caption' => 'Which one are you using first? Bookmark this so you don’t lose it! ⚡',
                'hashtags' => '#aitools #techhacks #productivity #shorts #viral',
                'virality_score' => 97,
                'published_days_ago' => 4,
            ],
            [
                'title' => 'Why 99% of Developers Are Prompting AI Completely Wrong',
                'duration' => 52,
                'status' => 'published',
                'hook' => 'This 1 prompting mistake is killing your code quality.',
                'caption' => 'Steal my 3-step prompt framework to generate production-ready code instantly.',
                'hashtags' => '#developer #coding #ai #chatgpt #tech',
                'virality_score' => 95,
                'published_days_ago' => 2,
            ],
            [
                'title' => 'Building a Micro SaaS in 24 Hours with Autonomous Agents',
                'duration' => 58,
                'status' => 'scheduled',
                'hook' => 'I let 5 AI agents build and deploy a real SaaS while I slept.',
                'caption' => 'Here is the step-by-step tech stack and exact revenue breakdown.',
                'hashtags' => '#saas #entrepreneur #buildinpublic #tech #shorts',
                'virality_score' => 94,
                'scheduled_days_ahead' => 1,
            ],
            [
                'title' => 'The #1 Shortcut in VS Code That Senior Engineers Use Daily',
                'duration' => 38,
                'status' => 'scheduled',
                'hook' => 'If you code without this extension, you are wasting 30 mins a day.',
                'caption' => 'Tag a fellow developer who needs this right now! 👇',
                'hashtags' => '#vscode #developer #programming #lifehacks',
                'virality_score' => 91,
                'scheduled_days_ahead' => 2,
            ],
            [
                'title' => 'Anthropic vs OpenAI vs Gemini: Speed Benchmark (2026)',
                'duration' => 49,
                'status' => 'ready',
                'hook' => 'We benchmarked the top 3 frontier models side-by-side.',
                'caption' => 'The winner shocked everyone. Watch till the end for the latency test!',
                'hashtags' => '#ai #gemini #claude #openai #benchmark',
                'virality_score' => 93,
            ],
            [
                'title' => 'The Exact 6 AM Routine of a 1M Subscriber YouTuber',
                'duration' => 55,
                'status' => 'ready',
                'hook' => 'Steal this morning routine and watch your retention explode.',
                'caption' => 'Consistency beats intensity — here is the full breakdown.',
                'hashtags' => '#creator #routine #youtube #discipline #shorts',
                'virality_score' => 90,
            ],
        ];

        foreach ($videosData1 as $vData) {
            $video = Video::create([
                'channel_id' => $channel1->id,
                'title' => $vData['title'],
                'duration' => $vData['duration'],
                'status' => $vData['status'],
                'ai_data' => [
                    'hook' => $vData['hook'],
                    'caption' => $vData['caption'],
                    'hashtags' => $vData['hashtags'],
                    'virality_score' => $vData['virality_score'],
                ],
            ]);

            if ($vData['status'] === 'published') {
                Publication::create([
                    'video_id' => $video->id,
                    'social_account_id' => $yt1->id,
                    'custom_title' => $video->title,
                    'custom_caption' => $vData['caption'],
                    'custom_hashtags' => $vData['hashtags'],
                    'scheduled_at' => Carbon::now()->subDays($vData['published_days_ago'])->setHour(18)->setMinute(0),
                    'published_at' => Carbon::now()->subDays($vData['published_days_ago'])->setHour(18)->setMinute(1),
                    'status' => 'published',
                    'post_url' => 'https://youtube.com/shorts/demo' . rand(1000, 9999),
                    'analytics' => ['views' => rand(12000, 96000), 'likes' => rand(900, 8200), 'comments' => rand(80, 900), 'shares' => rand(60, 1200)],
                ]);
            } elseif ($vData['status'] === 'scheduled') {
                Publication::create([
                    'video_id' => $video->id,
                    'social_account_id' => $yt1->id,
                    'custom_title' => $video->title,
                    'custom_caption' => $vData['caption'],
                    'custom_hashtags' => $vData['hashtags'],
                    'scheduled_at' => Carbon::tomorrow()->addDays($vData['scheduled_days_ahead'] - 1)->setHour(18)->setMinute(0),
                    'status' => 'scheduled',
                ]);
            }
        }

        // 3. Create Channel 2: FitLife Momentum
        $channel2 = Channel::create([
            'user_id' => $user->id,
            'name' => 'FitLife Momentum',
            'handle' => 'fitlife.momentum',
            'category' => 'Health & Fitness',
            'description' => 'Science-backed short-form training routines, nutrition hacks, and posture fixes.',
            'status' => 'active',
            'posts_per_day' => 1,
            'post_times' => ['18:00'],
        ]);

        SocialAccount::create([
            'channel_id' => $channel2->id,
            'platform' => 'youtube',
            'account_name' => 'FitLife Shorts',
            'handle' => '@fitlifeshorts',
            'follower_count' => 24100,
            'status' => 'connected',
            'credentials' => ['youtube_channel_id' => 'UC-demo-fitlife'],
        ]);

        Video::create([
            'channel_id' => $channel2->id,
            'title' => 'Fix Your Anterior Pelvic Tilt with This 60-Second Stretch',
            'duration' => 59,
            'status' => 'ready',
            'ai_data' => [
                'hook' => 'If you sit for more than 6 hours a day, do this right now.',
                'caption' => '3 quick mobility drills to unlock tight hip flexors and relieve lower back pressure.',
                'hashtags' => '#fitness #mobility #posture #health #shorts',
                'virality_score' => 96,
            ],
        ]);
    }
}
