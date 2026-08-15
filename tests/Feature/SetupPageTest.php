<?php

namespace Tests\Feature;

use Tests\TestCase;

class SetupPageTest extends TestCase
{
    private ?string $fixture = null;

    protected function tearDown(): void
    {
        if ($this->fixture !== null && is_dir($this->fixture)) {
            $this->deleteDir($this->fixture);
        }
        parent::tearDown();
    }

    private function deleteDir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$entry;
            if (is_dir($path) && ! is_link($path)) {
                $this->deleteDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /**
     * Build an isolated fake app in a temp dir: setup.php (a copy of the real
     * one), a minimal .env, a fake artisan script for the CLI steps, and a
     * harness that mimics a web request (method + POST body).
     */
    private function buildFixture(string $envContent, string $artisanCode): string
    {
        if (in_array('exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
            $this->markTestSkipped('exec() is disabled in this environment.');
        }

        $base = sys_get_temp_dir().'/omnishorts-setup-test-'.uniqid();
        $this->fixture = $base;
        mkdir($base.'/public', 0777, true);
        mkdir($base.'/storage/app/public', 0777, true);
        // Pre-existing target dir: the storage-link step reports "already
        // exists" instead of calling symlink() (avoids Windows privileges).
        mkdir($base.'/public/storage', 0777, true);
        copy(base_path('public/setup.php'), $base.'/public/setup.php');
        file_put_contents($base.'/.env', $envContent);
        file_put_contents($base.'/artisan', $artisanCode);
        file_put_contents($base.'/harness.php', '<?php
$_SERVER["REQUEST_METHOD"] = $argv[1];
$_GET = [];
$_POST = json_decode((string) file_get_contents($argv[2]), true) ?: [];
include $argv[3];
');

        return $base;
    }

    /**
     * @param  array<string, string>  $post
     */
    private function request(string $fixture, string $method, array $post = []): string
    {
        // The POST body travels via a file (cmd mangles JSON inside argv).
        $postFile = $fixture.'/post.json';
        file_put_contents($postFile, json_encode($post));

        $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($fixture.'/harness.php')
            .' '.escapeshellarg($method).' '.escapeshellarg($postFile)
            .' '.escapeshellarg($fixture.'/public/setup.php').' 2>&1';
        $output = [];
        exec($command, $output);

        return implode("\n", $output);
    }

    private function envWithToken(): string
    {
        return "APP_NAME=Test\nAPP_KEY=\nSETUP_TOKEN=secret-token-123\n";
    }

    private function fakeArtisan(bool $failOptimize = false): string
    {
        return '<?php
$cmd = (string) ($argv[1] ?? "");
if ($cmd === "optimize" && '.($failOptimize ? 'true' : 'false').') { fwrite(STDERR, "Boom\n"); exit(1); }
echo strtoupper($cmd)." OK\n";
';
    }

    public function test_shows_token_form_without_token(): void
    {
        $f = $this->buildFixture($this->envWithToken(), $this->fakeArtisan());

        $output = $this->request($f, 'GET');

        $this->assertStringContainsString('Setup token', $output);
        $this->assertFileExists($f.'/public/setup.php');
    }

    public function test_wrong_token_is_rejected(): void
    {
        $f = $this->buildFixture($this->envWithToken(), $this->fakeArtisan());

        $output = $this->request($f, 'POST', ['token' => 'wrong-token']);

        $this->assertStringContainsString('Access denied', $output);
        $this->assertStringContainsString('token you entered is incorrect', $output);
        $this->assertFileExists($f.'/public/setup.php');
    }

    public function test_refuses_to_run_without_configured_token(): void
    {
        $f = $this->buildFixture("APP_NAME=Test\nAPP_KEY=\n", $this->fakeArtisan());

        $output = $this->request($f, 'GET');

        $this->assertStringContainsString('Setup is locked', $output);
        $this->assertFileExists($f.'/public/setup.php');
    }

    public function test_successful_run_generates_key_and_self_deletes(): void
    {
        $f = $this->buildFixture($this->envWithToken(), $this->fakeArtisan());

        $output = $this->request($f, 'POST', ['token' => 'secret-token-123', 'action' => 'run']);

        $this->assertStringContainsString('Setup complete', $output);
        $this->assertStringContainsString('Generated and written', $output);
        $this->assertStringContainsString('MIGRATE OK', $output);
        $this->assertStringContainsString('OPTIMIZE OK', $output);
        $this->assertStringContainsString('VIDEOS:ENSURE-FILES OK', $output);
        $this->assertStringContainsString('has deleted itself', $output);
        // The repair file is gone after a fully successful run.
        $this->assertFileDoesNotExist($f.'/public/setup.php');
        // A real key landed in .env.
        $env = (string) file_get_contents($f.'/.env');
        $this->assertMatchesRegularExpression('/APP_KEY=base64:[A-Za-z0-9+\/=]{20,}/', $env);
    }

    public function test_failed_step_keeps_file_and_reports_honestly(): void
    {
        $f = $this->buildFixture(
            "APP_NAME=Test\nAPP_KEY=base64:existing-key-value-123456789==\nSETUP_TOKEN=secret-token-123\n",
            $this->fakeArtisan(failOptimize: true),
        );

        $output = $this->request($f, 'POST', ['token' => 'secret-token-123', 'action' => 'run']);

        $this->assertStringContainsString('Setup finished with errors', $output);
        $this->assertStringContainsString('Boom', $output);
        // Not deleted — the user can retry.
        $this->assertFileExists($f.'/public/setup.php');
        // The existing key was never overwritten.
        $this->assertStringContainsString('APP_KEY=base64:existing-key-value-123456789==', (string) file_get_contents($f.'/.env'));
    }

    public function test_existing_key_is_never_overwritten(): void
    {
        $f = $this->buildFixture(
            "APP_NAME=Test\nAPP_KEY=base64:keep-me-1234567890123456==\nSETUP_TOKEN=secret-token-123\n",
            $this->fakeArtisan(),
        );

        $output = $this->request($f, 'POST', ['token' => 'secret-token-123', 'action' => 'run']);

        $this->assertStringContainsString('Already set', $output);
        $this->assertStringContainsString('APP_KEY=base64:keep-me-1234567890123456==', (string) file_get_contents($f.'/.env'));
    }
}
