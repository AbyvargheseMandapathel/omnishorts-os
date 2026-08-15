# Release Notes

## [Unreleased](https://github.com/laravel/laravel/compare/v13.9.0...13.x)

* Google OAuth: surface the real reason when the token exchange or YouTube channels fetch fails (API not enabled, redirect URI mismatch, invalid credentials, etc.) instead of a generic message; also use per-account client credentials in the callback during reconnect
* Google OAuth: connection failures in the connect popup now render inside the popup (with Try Again / Close) and reload the opener with the error banner, instead of navigating the popup to the full accounts page
* Storage: video uploads are routed through the configurable `VIDEO_DISK` (default `public`) with a ready-to-use `ftp` disk for keeping files off a shared hosting plan; deleting a video now also deletes its file, and a new `videos:prune-files` command (run daily by the scheduler) removes files of videos published past a retention window plus orphaned files
* Scheduling: each connected YouTube channel now has its own posting cron (`posts_per_day` + `post_times` on social accounts, editable from the YouTube Channels page via the Cron button), overriding the channel default; bulk upload, single-video scheduling, the calendar, and next-free-slot logic all follow the selected account's cron with per-account occupied slots; calendar default cron selector now goes up to 10 posts/day
* Gemini AI video analysis: new server-side `GeminiVideoAnalyzer` service (video File API upload, JSON generation with repair/validation, soft failure); Settings page with encrypted API key, free-form model name, enable toggle, and Test Connection; AI Analysis panel on the video page with copy buttons; the upload cron analyzes each video with Gemini right before publishing (fresh AI title/description/hashtags, video metadata updated, results stored per video with `analysis_status`/`model_used`/`analyzed_at`), reuses completed analyses on retries, and falls back to existing metadata on any Gemini failure so scheduled uploads are never lost
* App timezone now defaults to `Asia/Kolkata` (IST, UTC+5:30) — all scheduling times display in IST; override with `APP_TIMEZONE` in `.env`
* Dashboard Scheduler card: cron health widget showing last-checked time (stamped by the commands on every run), Running/Not running/Never-ran status with a stale warning, queued posts count, and a Run Now button that triggers the publish cron manually
* Real YouTube uploads: the publish cron (and Publish Now) now upload the video to the connected YouTube channel via the Data API v3 resumable protocol with the account's OAuth token, using the AI-generated (or existing) title, description, and hashtags, and save the real watch URL. Simulated URLs are only used when no OAuth credentials are configured (dev mode); real failures never fabricate URLs. Revoked tokens flag the account for one-click reconnect and re-queue the post
* Video playback: the video page now plays the actual uploaded file in a 9:16 player (native controls) when a file exists, falling back to the old mockup otherwise; storage symlink + CSP `media-src` configured so `/storage/videos/...` is web-served
* Reupload: already-published reels show a Reupload to YouTube card on the video page (account + upload time, defaults to next cron tick) that queues a fresh scheduled publication for a new real YouTube upload, with the previous published versions listed
* SQLite lock fix: the publish cron no longer wraps long Gemini/YouTube HTTP calls in a DB transaction (only the short final state update is transactional), and SQLite runs in WAL mode with a 10s busy timeout — web requests no longer hit "database is locked" while the cron uploads. Both guards are SQLite-only, so MySQL production is untouched
* YouTube uploads are always marked `madeForKids: false` (never made for kids)
* Content Library cards play reels inline — click the play button to start, click the video to pause/resume, and controls return when it ends; falls back to the mockup when no file is present
* Real YouTube analytics: published reels get actual views/likes/comments/shares fetched from the YouTube Analytics API (videos.list fallback) right after upload and then hourly via the new `analytics:refresh` command; the dashboard shows real totals, a 14-day views growth curve, and per-channel best performers. Fabricated numbers are gone — simulated/dev publishes simply show no stats until real ones arrive
* Auto-retry failed uploads: transient YouTube failures (quota, 5xx, 429, timeouts, network) are re-queued with exponential backoff (5→80 min, up to 5 attempts) instead of killing the post; permanent failures (invalid grant, forbidden) still mark the post failed. Retry state lives in `attempt_count`/`next_retry_at` on publications
* Dashboard real-data only: video duration is measured from the actual file at upload time via pure-PHP getID3 (no ffmpeg needed, works on shared hosting) and shows "—" when unknown instead of a random number; connected channels' subscriber counts are refreshed hourly from YouTube (`channels.list`) rather than the stale connect-time value; the demo seeder no longer fabricates analytics; and the fake random virality-score placeholder is gone — a score only appears after a real Gemini analysis
* Per-channel Gemini override: each channel can force Gemini AI analysis on or off (or follow the global toggle) from the Settings page; the cron and the video page honor the channel's override, so one channel can auto-analyze every upload while another uploads with plain metadata
* Video page Performance card: real per-reel views/likes/comments/shares across all published versions, plus a 14-day views growth curve from the metric snapshot history (same sparkline as the dashboard); empty state until real stats arrive; a Refresh Stats button fetches fresh YouTube numbers for that reel on demand; the card shows when stats were last refreshed (amber when older than 24h)
* Dashboard: a Refresh All button on the Performance card runs the full `analytics:refresh` on demand — fresh stats for every published reel plus subscriber counts
* Real-data hardening: dashboard totals/best-performers and the video page Performance card now only count publications with a real `youtube.com/watch?v=` URL — simulated publishes and demo rows with fabricated numbers no longer inflate any stat
* Analytics refresh reduced from hourly to twice daily (08:00 & 20:00) to cut YouTube API hits — stats still land right after each upload and via the on-demand Refresh buttons; the Settings job row, dashboard, and video page copy updated accordingly
* Settings Scheduler card: every cron job now shows its own "Last ran" time (each command stamps `cron.last_run.<job>` on every run; also visible via `php artisan cron:status`)
* Mobile layout fixes: the dashboard and video-page two-column grids collapse to one column, Recent Reels rows and YouTube channel cards wrap so action buttons never overflow off-screen, card headers/save rows wrap, and the cron job + per-channel override rows stack cleanly on phones
* Cron jobs management (Settings → Scheduler & Cron Jobs): master scheduler toggle plus per-job switches for auto-publish, analytics refresh, and file pruning — the guards live at the schedule level, so disabling only stops automatic runs while manual buttons (Run Now, Refresh All, CLI) keep working. Install/Sync and Uninstall buttons manage the OS entry (Windows Task or crontab), the exact Hostinger/cPanel crontab line is shown with a copy button, and the dashboard Scheduler card now shows an amber "Disabled" state when the scheduler is paused. New `cron:status` and `cron:uninstall` commands; the heartbeat runs unconditionally so the dashboard always knows the OS scheduler is alive
* Deployment fix: composer platform is pinned to PHP 8.3 + declared `ext-ftp`, so the lock file resolves on shared hosting (Hostinger) where the PHP 8.5-generated lock previously pulled Symfony 8.1 (needs PHP ≥ 8.4) and failed with "lock file does not contain a compatible set of packages"; the lock now installs cleanly on PHP 8.3+
* Deployment layout fix: a root `.htaccess` now routes every request into `public/` so the app works straight from a shared-hosting document root (Hostinger/cPanel) without changing the doc root — the previous deploy showed a 403 because the domain served the repo root, which has no `index.php`; the README gained a step-by-step Hostinger deploy section
* One-click deployment setup (Settings → Deployment & Setup): a button runs `key:generate` (only when no APP_KEY is set — never overwrites an existing one), `migrate --force`, `storage:link`, and `optimize`, each in its own subprocess so a fresh key is picked up before config caching; the card shows live status for each step (key set, migrations table, storage link, config cache) and reports per-step output honestly after a run; a freshly generated key signs the user back in because all sessions become invalid
* Standalone repair page (`public/setup.php`): runs the same post-deploy steps from the browser even when the Laravel app cannot boot (missing .env, empty APP_KEY, missing DB tables). Token-protected (`SETUP_TOKEN` from env, `.env`, or the file constant — refuses to run without one), does `key:generate` and `storage:link` in pure PHP, shells out for `migrate --force` + `optimize`, reports each step honestly, never overwrites an existing key, and deletes itself after a fully successful run (recreate with `git checkout public/setup.php`)
* CLI/web PHP mismatch fix: `setup.php`, the Settings Deployment button, and the displayed crontab line now auto-detect a PHP ≥ 8.3 CLI binary (probing `PHP_BINARY`, then `php8.4`/`php8.3`/`php` by actual version) instead of blindly using the default `php` — on shared hosts like Hostinger the web SAPI can be 8.3 while the CLI is 8.2, which made every artisan call fail Composer's platform check; `setup.php` also shows which binary it picked
* Public health diagnostics: `/health` (Laravel route) and `/health.php` (standalone probe that works even when the app cannot boot) report PHP version, DB connectivity, migration state, and storage writability, plus a real log-write probe / `laravel.log` tail — answering `200`/`503` JSON with each failing check spelled out and never echoing secrets, so a post-deploy 500 is diagnosable instead of a mystery
* Video-save failures are loud now: the video disk probe was added to both health endpoints (Laravel writes+deletes a probe file on the configured disk; `health.php` performs a real FTP login + probe write to `FTP_ROOT` when `VIDEO_DISK=ftp`), and single/bulk upload no longer silently creates file-less videos when the disk write fails (`store()` returning `false` or throwing is caught and reported as an upload error instead) — the fix for "my video wasn't saved" on a misconfigured FTP disk

## [v13.9.0](https://github.com/laravel/laravel/compare/v13.8.0...v13.9.0) - 2026-08-12

* GitHub Actions hardening by [@nunomaduro](https://github.com/nunomaduro) in https://github.com/laravel/laravel/pull/6829
* Bump actions/checkout from 6.0.2 to 6.0.3 in the github-actions group by [@dependabot](https://github.com/dependabot)[bot] in https://github.com/laravel/laravel/pull/6830
* Pin pull requests and issues workflows to least-privilege reusable workflows by [@nunomaduro](https://github.com/nunomaduro) in https://github.com/laravel/laravel/pull/6831
* Pin pull requests and issues workflows to latest laravel/.github by [@nunomaduro](https://github.com/nunomaduro) in https://github.com/laravel/laravel/pull/6832
* Add Dependabot cooldown of 5 days by [@nunomaduro](https://github.com/nunomaduro) in https://github.com/laravel/laravel/pull/6833
* Enable Dependabot auto-merge by [@nunomaduro](https://github.com/nunomaduro) in https://github.com/laravel/laravel/pull/6834
* Restore expectsJson fallback when rendering JSON exceptions by [@jasonvarga](https://github.com/jasonvarga) in https://github.com/laravel/laravel/pull/6837
* Bump shivammathur/setup-php from 2.37.1 to 2.37.2 in the github-actions group by [@dependabot](https://github.com/dependabot)[bot] in https://github.com/laravel/laravel/pull/6838
* Add 'array' as a supported maintenance mode driver doc block by [@jackbayliss](https://github.com/jackbayliss) in https://github.com/laravel/laravel/pull/6840
* Bump actions/checkout from 6.0.3 to 7.0.0 in the github-actions group by [@dependabot](https://github.com/dependabot)[bot] in https://github.com/laravel/laravel/pull/6841
* Bump Concurrently to v10 by [@u01jmg3](https://github.com/u01jmg3) in https://github.com/laravel/laravel/pull/6845
* Update APP_URL to use port 8000 by [@dipesh79](https://github.com/dipesh79) in https://github.com/laravel/laravel/pull/6846
* Add monthly log driver to `config/logging.php` by [@SjorsO](https://github.com/SjorsO) in https://github.com/laravel/laravel/pull/6847
* Bump actions/checkout from 7.0.0 to 7.0.1 in the github-actions group by [@dependabot](https://github.com/dependabot)[bot] in https://github.com/laravel/laravel/pull/6848
* Use new `artisan dev` command in `composer dev` script by [@WendellAdriel](https://github.com/WendellAdriel) in https://github.com/laravel/laravel/pull/6849
* Add `@laravel/multiplex` by [@joetannenbaum](https://github.com/joetannenbaum) in https://github.com/laravel/laravel/pull/6854

## [v13.8.0](https://github.com/laravel/laravel/compare/v13.7.0...v13.8.0) - 2026-05-25

* [13.x] remove Tailwind `@source` calls that are already covered by default by [@browner12](https://github.com/browner12) in https://github.com/laravel/laravel/pull/6823
* [13.x] Render JSON exceptions for API routes by default by [@LucasCavalheri](https://github.com/LucasCavalheri) in https://github.com/laravel/laravel/pull/6824

## [v13.7.0](https://github.com/laravel/laravel/compare/v13.6.0...v13.7.0) - 2026-05-14

**Full Changelog**: https://github.com/laravel/laravel/compare/v13.6.0...v13.7.0

## [v13.6.0](https://github.com/laravel/laravel/compare/v13.5.0...v13.6.0) - 2026-05-11

* Remove Pdo/Mysql const workaround by [@jnoordsij](https://github.com/jnoordsij) in https://github.com/laravel/laravel/pull/6810

## [v13.5.0](https://github.com/laravel/laravel/compare/v13.4.0...v13.5.0) - 2026-04-30

* Use the Vite font plugin for application fonts by [@WendellAdriel](https://github.com/WendellAdriel) in https://github.com/laravel/laravel/pull/6806

## [v13.4.0](https://github.com/laravel/laravel/compare/v13.3.0...v13.4.0) - 2026-04-28

* Add @no_additional_args to composer test script config clear by [@jnoordsij](https://github.com/jnoordsij) in https://github.com/laravel/laravel/pull/6799
* [13.x] Adds pao by default by [@nunomaduro](https://github.com/nunomaduro) in https://github.com/laravel/laravel/pull/6802

## [v13.3.0](https://github.com/laravel/laravel/compare/v13.2.0...v13.3.0) - 2026-04-16

* [13.x] enable npm audit by default by [@leo95batista](https://github.com/leo95batista) in https://github.com/laravel/laravel/pull/6788
* Update changelog link to Laravel framework repo by [@Rattone](https://github.com/Rattone) in https://github.com/laravel/laravel/pull/6790
* [13x] Add .codex to .gitignore by [@amdad121](https://github.com/amdad121) in https://github.com/laravel/laravel/pull/6793

## [v13.2.0](https://github.com/laravel/laravel/compare/v13.1.2...v13.2.0) - 2026-04-09

* Remove axios and enable ignore-scripts by [@WendellAdriel](https://github.com/WendellAdriel) in https://github.com/laravel/laravel/pull/6778
* Add /.cursor/ to .gitignore by [@workwithbinu](https://github.com/workwithbinu) in https://github.com/laravel/laravel/pull/6782
* Remove '.fleet' from .gitignore by [@dominiq007](https://github.com/dominiq007) in https://github.com/laravel/laravel/pull/6783
* Support all compose file naming conventions in editorconfig by [@mmachatschek](https://github.com/mmachatschek) in https://github.com/laravel/laravel/pull/6786

## [v13.1.2](https://github.com/laravel/laravel/compare/v13.1.1...v13.1.2) - 2026-03-31

* Prevents installed package from executing malicious code via `postinstall` by [@crynobone](https://github.com/crynobone) in https://github.com/laravel/laravel/pull/6777
* Add missing comma in axios by [@aziyan99](https://github.com/aziyan99) in https://github.com/laravel/laravel/pull/6779

## [v13.1.1](https://github.com/laravel/laravel/compare/v13.1.0...v13.1.1) - 2026-03-31

* Update .gitignore by [@Cegem-360](https://github.com/Cegem-360) in https://github.com/laravel/laravel/pull/6774
* [security] pin axios version by [@NickSdot](https://github.com/NickSdot) in https://github.com/laravel/laravel/pull/6776

## [v13.1.0](https://github.com/laravel/laravel/compare/v12.12.2...v13.1.0) - 2026-03-18

* Change back minimum-stability to stable by [@jnoordsij](https://github.com/jnoordsij) in https://github.com/laravel/laravel/pull/6766
* Vite 8 support

## [v12.12.2](https://github.com/laravel/laravel/compare/v12.12.1...v12.12.2) - 2026-03-14

* [12.x] Add `APP_NAME` fallback in Slack log channel username by [@hamedelasma](https://github.com/hamedelasma) in https://github.com/laravel/laravel/pull/6762

## [v12.12.1](https://github.com/laravel/laravel/compare/v12.12.0...v12.12.1) - 2026-03-10

* [12.x] Makes imports consistent by [@nunomaduro](https://github.com/nunomaduro) in https://github.com/laravel/laravel/pull/6760

## [v12.12.0](https://github.com/laravel/laravel/compare/v12.11.2...v12.12.0) - 2026-03-09

* Update phpunit version to ^11.5.50 to address CVE by [@PerryvanderMeer](https://github.com/PerryvanderMeer) in https://github.com/laravel/laravel/pull/6746
* [12.x] Add `APP_NAME` fallback in mail config by [@apoorvdarshan](https://github.com/apoorvdarshan) in https://github.com/laravel/laravel/pull/6755
* [12.x] Neutralize DB_URL in default phpunit.xml by [@Husseinadq](https://github.com/Husseinadq) in https://github.com/laravel/laravel/pull/6761

## [v12.11.2](https://github.com/laravel/laravel/compare/v12.11.1...v12.11.2) - 2026-01-19

* [12.x] Update composer dev script to ensure no timeout by [@jackbayliss](https://github.com/jackbayliss) in https://github.com/laravel/laravel/pull/6735
* [12.x] Update jobs/cache migrations by [@jackbayliss](https://github.com/jackbayliss) in https://github.com/laravel/laravel/pull/6736
* [12.x] Remove failed jobs indexes by [@jackbayliss](https://github.com/jackbayliss) in https://github.com/laravel/laravel/pull/6739
* [12.x] Add `APP_URL` fallback in filesystems config by [@KentarouTakeda](https://github.com/KentarouTakeda) in https://github.com/laravel/laravel/pull/6742
* chore: Update outdated GitHub Actions version by [@pgoslatara](https://github.com/pgoslatara) in https://github.com/laravel/laravel/pull/6743

## [v12.11.1](https://github.com/laravel/laravel/compare/v12.11.0...v12.11.1) - 2025-12-23

* Use environment variable for `DB_SSLMODE` - Postgres by [@robsontenorio](https://github.com/robsontenorio) in https://github.com/laravel/laravel/pull/6727
* fix: ensure APP_URL does not have trailing slash in filesystem by [@msamgan](https://github.com/msamgan) in https://github.com/laravel/laravel/pull/6728

## [v12.11.0](https://github.com/laravel/laravel/compare/v12.10.1...v12.11.0) - 2025-11-25

* fix: cookies are not available for subdomains by default by [@joostdebruijn](https://github.com/joostdebruijn) in https://github.com/laravel/laravel/pull/6705
* Fix PHP 8.5 PDO Driver Specific Constant Deprecation by [@RyanSchaefer](https://github.com/RyanSchaefer) in https://github.com/laravel/laravel/pull/6710
* Ignore Laravel compiled views for Vite  by [@QistiAmal1212](https://github.com/QistiAmal1212) in https://github.com/laravel/laravel/pull/6714

## [v12.10.1](https://github.com/laravel/laravel/compare/v12.10.0...v12.10.1) - 2025-11-06

* Update schema URL in package.json by [@robinmiau](https://github.com/robinmiau) in https://github.com/laravel/laravel/pull/6701

## [v12.10.0](https://github.com/laravel/laravel/compare/v12.9.1...v12.10.0) - 2025-11-04

* Add background driver by [@barryvdh](https://github.com/barryvdh) in https://github.com/laravel/laravel/pull/6699

## [v12.9.1](https://github.com/laravel/laravel/compare/v12.9.0...v12.9.1) - 2025-10-23

* [12.x] Replace Bootcamp with Laravel Learn by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6692
* [12.x] Comment out CLI workers for fresh applications by [@timacdonald](https://github.com/timacdonald) in https://github.com/laravel/laravel/pull/6693

## [v12.9.0](https://github.com/laravel/laravel/compare/v12.8.0...v12.9.0) - 2025-10-21

**Full Changelog**: https://github.com/laravel/laravel/compare/v12.8.0...v12.9.0

## [v12.8.0](https://github.com/laravel/laravel/compare/v12.7.1...v12.8.0) - 2025-10-20

* [12.x] Makes test suite using broadcast's `null` driver by [@nunomaduro](https://github.com/nunomaduro) in https://github.com/laravel/laravel/pull/6691

## [v12.7.1](https://github.com/laravel/laravel/compare/v12.7.0...v12.7.1) - 2025-10-15

* Added `failover` driver to the `queue` config comment.  by [@sajjadhossainshohag](https://github.com/sajjadhossainshohag) in https://github.com/laravel/laravel/pull/6688

## [v12.7.0](https://github.com/laravel/laravel/compare/v12.6.0...v12.7.0) - 2025-10-14

**Full Changelog**: https://github.com/laravel/laravel/compare/v12.6.0...v12.7.0

## [v12.6.0](https://github.com/laravel/laravel/compare/v12.5.0...v12.6.0) - 2025-10-02

* Fix setup script by [@goldmont](https://github.com/goldmont) in https://github.com/laravel/laravel/pull/6682

## [v12.5.0](https://github.com/laravel/laravel/compare/v12.4.0...v12.5.0) - 2025-09-30

* [12.x] Fix type casting for environment variables in config files by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6670
* Fix CVEs affecting vite by [@faissaloux](https://github.com/faissaloux) in https://github.com/laravel/laravel/pull/6672
* Update .editorconfig to target compose.yaml by [@fredikaputra](https://github.com/fredikaputra) in https://github.com/laravel/laravel/pull/6679
* Add pre-package-uninstall script to composer.json by [@cosmastech](https://github.com/cosmastech) in https://github.com/laravel/laravel/pull/6681

## [v12.4.0](https://github.com/laravel/laravel/compare/v12.3.1...v12.4.0) - 2025-08-29

* [12.x] Add default Redis retry configuration by [@mateusjatenee](https://github.com/mateusjatenee) in https://github.com/laravel/laravel/pull/6666

## [v12.3.1](https://github.com/laravel/laravel/compare/v12.3.0...v12.3.1) - 2025-08-21

* [12.x] Bump Pint version by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6653
* [12.x] Making sure all related processed are closed when terminating the currently command by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6654
* [12.x] Use application name from configuration by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6655
* Bring back postAutoloadDump script by [@jasonvarga](https://github.com/jasonvarga) in https://github.com/laravel/laravel/pull/6662

## [v12.3.0](https://github.com/laravel/laravel/compare/v12.2.0...v12.3.0) - 2025-08-03

* Fix Critical Security Vulnerability in form-data Dependency by [@izzygld](https://github.com/izzygld) in https://github.com/laravel/laravel/pull/6645
* Revert "fix" by [@RobertBoes](https://github.com/RobertBoes) in https://github.com/laravel/laravel/pull/6646
* Change composer post-autoload-dump script to Artisan command by [@lmjhs](https://github.com/lmjhs) in https://github.com/laravel/laravel/pull/6647

## [v12.2.0](https://github.com/laravel/laravel/compare/v12.1.0...v12.2.0) - 2025-07-11

* Add Vite 7 support by [@timacdonald](https://github.com/timacdonald) in https://github.com/laravel/laravel/pull/6639

## [v12.1.0](https://github.com/laravel/laravel/compare/v12.0.11...v12.1.0) - 2025-07-03

* [12.x] Disable nightwatch in testing by [@laserhybiz](https://github.com/laserhybiz) in https://github.com/laravel/laravel/pull/6632
* [12.x] Reorder environment variables in phpunit.xml for logical grouping by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6634
* Change to hyphenate prefixes and cookie names by [@u01jmg3](https://github.com/u01jmg3) in https://github.com/laravel/laravel/pull/6636
* [12.x] Fix type casting for environment variables in config files by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6637

## [v12.0.11](https://github.com/laravel/laravel/compare/v12.0.10...v12.0.11) - 2025-06-10

**Full Changelog**: https://github.com/laravel/laravel/compare/v12.0.10...v12.0.11

## [v12.0.10](https://github.com/laravel/laravel/compare/v12.0.9...v12.0.10) - 2025-06-09

* fix alphabetical order by [@Khuthaily](https://github.com/Khuthaily) in https://github.com/laravel/laravel/pull/6627
* [12.x] Reduce redundancy and keeps the .gitignore file cleaner by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6629
* [12.x] Fix: Add void return type to satisfy Rector analysis by [@Aluisio-Pires](https://github.com/Aluisio-Pires) in https://github.com/laravel/laravel/pull/6628

## [v12.0.9](https://github.com/laravel/laravel/compare/v12.0.8...v12.0.9) - 2025-05-26

* [12.x] Remove apc by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6611
* [12.x] Add JSON Schema to package.json by [@martinbean](https://github.com/martinbean) in https://github.com/laravel/laravel/pull/6613
* Minor language update by [@woganmay](https://github.com/woganmay) in https://github.com/laravel/laravel/pull/6615
* Enhance .gitignore to exclude common OS and log files by [@mohammadRezaei1380](https://github.com/mohammadRezaei1380) in https://github.com/laravel/laravel/pull/6619

## [v12.0.8](https://github.com/laravel/laravel/compare/v12.0.7...v12.0.8) - 2025-05-12

* [12.x] Clean up URL formatting in README by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6601

## [v12.0.7](https://github.com/laravel/laravel/compare/v12.0.6...v12.0.7) - 2025-04-15

* Add `composer run test` command by [@crynobone](https://github.com/crynobone) in https://github.com/laravel/laravel/pull/6598
* Partner Directory Changes in ReadME by [@joshcirre](https://github.com/joshcirre) in https://github.com/laravel/laravel/pull/6599

## [v12.0.6](https://github.com/laravel/laravel/compare/v12.0.5...v12.0.6) - 2025-04-08

**Full Changelog**: https://github.com/laravel/laravel/compare/v12.0.5...v12.0.6

## [v12.0.5](https://github.com/laravel/laravel/compare/v12.0.4...v12.0.5) - 2025-04-02

* [12.x] Update `config/mail.php` to match the latest core configuration by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6594

## [v12.0.4](https://github.com/laravel/laravel/compare/v12.0.3...v12.0.4) - 2025-03-31

* Bump vite from 6.0.11 to 6.2.3 - Vulnerability patch by [@abdel-aouby](https://github.com/abdel-aouby) in https://github.com/laravel/laravel/pull/6586
* Bump vite from 6.2.3 to 6.2.4 by [@thinkverse](https://github.com/thinkverse) in https://github.com/laravel/laravel/pull/6590

## [v12.0.3](https://github.com/laravel/laravel/compare/v12.0.2...v12.0.3) - 2025-03-17

* Remove reverted change from CHANGELOG.md by [@AJenbo](https://github.com/AJenbo) in https://github.com/laravel/laravel/pull/6565
* Improves clarity in app.css file by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6569
* [12.x] Refactor: Structural improvement for clarity by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6574
* Bump axios from 1.7.9 to 1.8.2 - Vulnerability patch by [@abdel-aouby](https://github.com/abdel-aouby) in https://github.com/laravel/laravel/pull/6572
* [12.x] Remove Unnecessarily [@source](https://github.com/source) by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6584

## [v12.0.2](https://github.com/laravel/laravel/compare/v12.0.1...v12.0.2) - 2025-03-04

* Make the github test action run out of the box independent of the choice of testing framework by [@ndeblauw](https://github.com/ndeblauw) in https://github.com/laravel/laravel/pull/6555

## [v12.0.1](https://github.com/laravel/laravel/compare/v12.0.0...v12.0.1) - 2025-02-24

* [12.x] prefer stable stability by [@pataar](https://github.com/pataar) in https://github.com/laravel/laravel/pull/6548

## [v12.0.0 (2025-??-??)](https://github.com/laravel/laravel/compare/v11.0.2...v12.0.0)

Laravel 12 includes a variety of changes to the application skeleton. Please consult the diff to see what's new.
