<?php

namespace App\Services;

use Symfony\Component\Process\Process;

/**
 * Runs artisan commands in their own subprocess (a fresh bootstrap each time),
 * so a new APP_KEY from an earlier step is picked up before config caching and
 * database migrations see the current .env, not the request's snapshot.
 */
class DeployService
{
    /**
     * @param  list<string>  $arguments  e.g. ['migrate', '--force']
     * @return array{exit: int|null, output: string}
     */
    public function runArtisan(array $arguments): array
    {
        $process = new Process(
            array_merge([PHP_BINARY, base_path('artisan')], $arguments),
            base_path(),
        );
        $process->setTimeout(300);
        $process->run();

        return [
            'exit' => $process->getExitCode(),
            'output' => trim($process->getOutput().$process->getErrorOutput()),
        ];
    }
}
