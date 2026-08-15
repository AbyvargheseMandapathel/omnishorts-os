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
     * Find a PHP CLI binary that can run the app (>= 8.3). On shared hosts the
     * web SAPI is often newer than the CLI "php" on PATH (Hostinger: web 8.3,
     * CLI 8.2), so Composer's platform check kills artisan under the default
     * binary. The first candidate that actually reports >= 8.3 wins.
     */
    public static function phpBinary(): string
    {
        static $binary = null;
        if ($binary !== null) {
            return $binary;
        }

        $candidates = array_values(array_unique(array_filter([
            PHP_BINARY,
            PHP_BINDIR.'/php8.4', PHP_BINDIR.'/php84', PHP_BINDIR.'/php8.3', PHP_BINDIR.'/php83', PHP_BINDIR.'/php',
            'php8.4', 'php84', 'php8.3', 'php83', 'php',
            '/usr/bin/php8.4', '/usr/bin/php8.3', '/usr/bin/lsphp84', '/usr/bin/lsphp83',
            '/usr/local/bin/php8.4', '/usr/local/bin/php8.3', '/usr/local/lsws/lsphp84/bin/lsphp', '/usr/local/lsws/lsphp83/bin/lsphp',
        ], fn (string $candidate) => $candidate !== '')));

        foreach ($candidates as $candidate) {
            $output = [];
            $code = 1;
            @exec(escapeshellarg($candidate).' -r '.escapeshellarg('echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;').' 2>&1', $output, $code);
            $version = trim((string) ($output[0] ?? ''));
            if ($code === 0 && preg_match('/^\d+\.\d+$/', $version) && version_compare($version, '8.3', '>=')) {
                return $binary = $candidate;
            }
        }

        return $binary = PHP_BINARY;
    }

    /**
     * @param  list<string>  $arguments  e.g. ['migrate', '--force']
     * @return array{exit: int|null, output: string}
     */
    public function runArtisan(array $arguments): array
    {
        $process = new Process(
            array_merge([self::phpBinary(), base_path('artisan')], $arguments),
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
