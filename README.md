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

- **Backend:** Laravel 13 / PHP 8.3+
- **Frontend:** Blade Templates & Custom Vanilla CSS Design System (Obsidian Dark Theme)
- **Database:** SQLite / MySQL / PostgreSQL
- **APIs:** Google Identity Services & YouTube Data API v3

## 🚀 Deploying to Hostinger (shared hosting)

The repo ships with a root `.htaccess` that routes every request into `public/`, so the site works straight from `public_html` — no document-root changes needed. The lock file is platform-pinned to PHP 8.3, so `composer install` succeeds even where `ext-ftp` is missing (it only matters if you actually use the FTP disk).

1. **PHP version:** hPanel → Websites → your site → Advanced → set PHP to **8.3 or newer** (Laravel 13 requires it).
2. **Upload the app:** clone the repo into the site folder (e.g. `public_html`) — the root `.htaccess` does the rest.
3. **Dependencies:** `composer install --no-dev --optimize-autoloader`.
4. **Environment:** copy `.env.example` to `.env`, fill in real values (MySQL, FTP, Google OAuth, Gemini), then `php artisan key:generate`. Secrets are encrypted with the server's key — re-enter your Google client secret and Gemini key once in the app.
5. **Storage & DB:** `php artisan storage:link`, `php artisan migrate --force`, and ensure `storage/` + `bootstrap/cache/` are writable.
6. **Cron:** Settings → Scheduler & Cron Jobs shows the exact crontab line for this server (real PHP/artisan paths) — paste it into hPanel → Cron Jobs.

> If you prefer the cleaner layout, set the document root to `public_html/public` in hPanel and delete the root `.htaccess`.

### Troubleshooting: 500 error right after deploy

A **500** (not 403) means Apache is reaching Laravel but the app can't boot. On a fresh clone the cause is almost always a missing or empty `.env` — it's gitignored, so the server has none, and an empty `APP_KEY` makes every request fail with 500. From Hostinger's hPanel **Terminal** (or SSH), inside the app folder:

```bash
php -v                            # must be 8.3+ (set per-site in hPanel if not)
cp .env.example .env              # then fill in real values (DB, APP_URL, Google, Gemini)
php artisan key:generate          # REQUIRED — empty APP_KEY = 500 on every page
composer install --no-dev --optimize-autoloader
chmod -R 775 storage bootstrap/cache
php artisan migrate --force
php artisan storage:link
php artisan optimize
```

To see the exact error instead of guessing, temporarily set `APP_DEBUG=true` in `.env`, reload, then flip it back. The in-app **Settings → Deployment & Setup** button does all of this for you — but it needs the app bootable first, so the very first `key:generate` must come from the terminal.

### No terminal? `public/setup.php`

If you can't reach a terminal (or the app won't boot at all), the repo ships a **standalone repair page** at `public/setup.php` that runs the same steps from the browser even when the Laravel app cannot boot. It does `key:generate` and `storage:link` in pure PHP (no shell needed) and runs `migrate --force` + `optimize` via the PHP CLI.

1. Make sure the file exists in the deployed `public/` folder (`git checkout public/setup.php` if not).
2. Add a secret to `.env`: `SETUP_TOKEN=some-long-random-string`.
3. Open `https://your-site/setup.php`, enter the token, click **Run deployment setup**.
4. The page reports each step honestly and **deletes itself after a fully successful run**. To bring it back later, re-run `git checkout public/setup.php`.

If `SETUP_TOKEN` is missing the page refuses to do anything. Never leave `setup.php` on a production server after setup.

### CLI PHP older than the web PHP (Hostinger)

A common Hostinger trap: the **web** SAPI runs PHP 8.3+ (site loads, app boots) but the **CLI** `php` on PATH is older (e.g. 8.2), so every `php artisan ...` command dies with *"Your Composer dependencies require a PHP version \">= 8.3.0\". You are running 8.2.x"* — and the scheduler cron silently fails. `setup.php`, the Settings **Run Deployment Setup** button, and the displayed crontab line all auto-detect a PHP ≥ 8.3 binary (`php8.4`/`php8.3` first), so they keep working. For manual/cron usage, use the versioned binary too:

```bash
php8.3 artisan migrate --force     # or php8.4, whatever exists (ls /usr/bin/php*)
# crontab line:  * * * * * cd /home/.../public_html && php8.3 artisan schedule:run >> /dev/null 2>&1
```

Check what you have with `ls /usr/bin/php*` and `php -v`. Prefer enabling PHP 8.3+ for the whole site in hPanel → Websites → PHP Configuration.

