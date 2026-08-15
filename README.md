# 🚀 OmniShorts OS — Multi-Channel Short-Form Content Engine

OmniShorts is a modern, full-stack content operating system built for digital creators, media agencies, and video producers to streamline vertical video production (YouTube Shorts, TikTok, Instagram Reels, Facebook).

## ✨ Key Features

- 🎯 **Multi-Channel Management:** Switch seamlessly between distinct channel brands, niches, and linked social accounts.
- ⚡ **AI Viral Studio:** Generate high-retention 3-second opening hooks, virality scores, and multi-platform SEO captions with GPT/Gemini algorithms. Per-channel override — one channel can auto-analyze every upload with Gemini while another uploads with plain metadata.
- 📅 **Interactive Visual Calendar:** Visual 7-day scheduling grid with drag-and-drop rescheduling and unscheduled video tray.
- ⏱️ **Cron Jobs Manager (Settings):** master + per-job enable/disable for auto-publish, analytics refresh, and file pruning; Install/Sync and Uninstall for the OS entry (Windows Task or crontab), plus the exact Hostinger/cPanel crontab line. Manual runs keep working when automatic jobs are paused.
- 📄 **Server cron:** one OS entry running `artisan schedule:run` every minute (`php artisan cron:install` auto-creates it on Windows or Linux); check state anytime with `php artisan cron:status`.
- 📦 **Bulk Upload Pack:** Queue dozens of short clips in one batch with automated cron-based time-slot distribution.
- ⏰ **Per-Channel Crons:** Every connected YouTube channel gets its own posting schedule (posts/day + exact times), with per-account occupied slots and a channel-wide default fallback.
- 📊 **Real YouTube Analytics:** Published reels pull actual views/likes/comments/shares from the YouTube Analytics API right after upload and twice daily (08:00 & 20:00) via `php artisan analytics:refresh` — deliberately infrequent to keep API quota low, with on-demand Refresh buttons when you want fresher numbers. The dashboard shows real totals, a 14-day views growth curve, per-channel best performers, and refreshed subscriber counts. Nothing on the dashboard is fabricated — unknown values render as "—" until real data arrives (video duration is measured from the file via getID3, not random).
- 🔁 **Auto-Retry Uploads:** Transient YouTube failures (quota, 5xx, timeouts) are re-queued with exponential backoff (up to 5 attempts) instead of losing the scheduled post; permanent failures (revoked token, forbidden) flag the account for one-click reconnect.
- 🎨 **Brand Presets & 9:16 Mockup:** Customize kinetic caption styles (Hormozi, MrBeast bounce), colors, fonts, and live mobile preview.
- 🔐 **One-Click Google Sign-In:** Secure authentication using Google Identity Services (GIS).

## 🛠️ Tech Stack

- **Backend:** Laravel 11 / PHP 8.2+
- **Frontend:** Blade Templates & Custom Vanilla CSS Design System (Obsidian Dark Theme)
- **Database:** SQLite / MySQL / PostgreSQL
- **APIs:** Google Identity Services & YouTube Data API v3
