# 🚀 OmniShorts OS — Multi-Channel Short-Form Content Engine

OmniShorts is a modern, full-stack content operating system built for digital creators, media agencies, and video producers to streamline vertical video production (YouTube Shorts, TikTok, Instagram Reels, Facebook).

## ✨ Key Features

- 🎯 **Multi-Channel Management:** Switch seamlessly between distinct channel brands, niches, and linked social accounts.
- ⚡ **AI Viral Studio:** Generate high-retention 3-second opening hooks, virality scores, and multi-platform SEO captions with GPT/Gemini algorithms.
- 📅 **Interactive Visual Calendar:** Visual 7-day scheduling grid with drag-and-drop rescheduling and unscheduled video tray.
- 📦 **Bulk Upload Pack:** Queue dozens of short clips in one batch with automated cron-based time-slot distribution.
- ⏰ **Per-Channel Crons:** Every connected YouTube channel gets its own posting schedule (posts/day + exact times), with per-account occupied slots and a channel-wide default fallback.
- 📊 **Real YouTube Analytics:** Published reels pull actual views/likes/comments/shares from the YouTube Analytics API right after upload and hourly via `php artisan analytics:refresh`; the dashboard shows real totals, a 14-day views growth curve, and per-channel best performers.
- 🔁 **Auto-Retry Uploads:** Transient YouTube failures (quota, 5xx, timeouts) are re-queued with exponential backoff (up to 5 attempts) instead of losing the scheduled post; permanent failures (revoked token, forbidden) flag the account for one-click reconnect.
- 🎨 **Brand Presets & 9:16 Mockup:** Customize kinetic caption styles (Hormozi, MrBeast bounce), colors, fonts, and live mobile preview.
- 🔐 **One-Click Google Sign-In:** Secure authentication using Google Identity Services (GIS).

## 🛠️ Tech Stack

- **Backend:** Laravel 11 / PHP 8.2+
- **Frontend:** Blade Templates & Custom Vanilla CSS Design System (Obsidian Dark Theme)
- **Database:** SQLite / MySQL / PostgreSQL
- **APIs:** Google Identity Services & YouTube Data API v3
