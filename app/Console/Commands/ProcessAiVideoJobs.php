<?php

namespace App\Console\Commands;

use App\Models\AiVideoJob;
use App\Models\Setting;
use App\Services\Ai\AiVideoPipeline;
use App\Services\Ai\VideoApprover;
use Illuminate\Console\Command;

class ProcessAiVideoJobs extends Command
{
    protected $signature = 'ai:process-jobs {--id= : Process a specific job id (used by retries)} {--limit=5 : Max jobs per run}';

    protected $description = 'Process queued AI video generation jobs';

    public function handle(AiVideoPipeline $pipeline): int
    {
        Setting::set('cron.last_checked', now()->toDateTimeString());

        $query = AiVideoJob::query()
            ->where('status', AiVideoJob::STATUS_QUEUED)
            ->orderBy('updated_at');

        if ($id = $this->option('id')) {
            $query->whereKey($id);
        }

        $jobs = $query->limit((int) $this->option('limit'))->get();

        if ($jobs->isEmpty()) {
            $this->info('No queued AI video jobs.');

            return self::SUCCESS;
        }

        foreach ($jobs as $job) {
            $this->info("Processing AI video job #{$job->id} (stage: {$job->stage}).");
            $pipeline->process($job);

            $job->refresh();

            // Daily auto-generation jobs skip the manual Approve step — the
            // finished render becomes a Content Library video immediately.
            if ($job->auto_approve && $job->status === AiVideoJob::STATUS_COMPLETED && ! $job->video_id) {
                try {
                    $video = app(VideoApprover::class)->approve($job);
                    $this->line("Job #{$job->id} -> auto-approved as video #{$video->id}");

                    continue;
                } catch (\Throwable $e) {
                    $this->error("Job #{$job->id} completed but auto-approve failed: {$e->getMessage()}");
                }
            }

            $state = $job->status === AiVideoJob::STATUS_COMPLETED
                ? 'completed'
                : "failed: {$job->error}";
            $this->line("Job #{$job->id} -> {$state}");
        }

        return self::SUCCESS;
    }
}
