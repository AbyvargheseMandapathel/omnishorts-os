<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\DeployService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeploySetupTest extends TestCase
{
    use RefreshDatabase;

    private function createUserWithChannel(): User
    {
        $user = User::factory()->create();
        $channel = Channel::create(['user_id' => $user->id, 'name' => 'Test Channel']);
        SocialAccount::create([
            'channel_id' => $channel->id,
            'platform' => 'youtube',
            'account_name' => 'Test YT',
            'status' => 'connected',
        ]);

        return $user;
    }

    public function test_run_deploy_requires_auth(): void
    {
        $this->post(route('settings.deploy.run'))->assertRedirect(route('login'));
    }

    public function test_run_deploy_skips_key_generation_when_key_exists(): void
    {
        $runner = $this->mock(DeployService::class);
        $runner->shouldReceive('runArtisan')->with(['migrate', '--force'])->once()->andReturn(['exit' => 0, 'output' => 'Migrated.']);
        $runner->shouldReceive('runArtisan')->with(['storage:link'])->once()->andReturn(['exit' => 0, 'output' => 'The [public/storage] link has been connected.']);
        $runner->shouldReceive('runArtisan')->with(['optimize'])->once()->andReturn(['exit' => 0, 'output' => 'Routes cached. Config cached.']);
        $runner->shouldNotReceive('runArtisan')->with(['key:generate', '--force']);

        $user = $this->createUserWithChannel();

        $response = $this->actingAs($user)->post(route('settings.deploy.run'));

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success');
        $response->assertSessionHas('deploy_results');

        $results = session('deploy_results');
        $this->assertSame('skipped', $results[0]['status']);
        $this->assertSame('done', $results[1]['status']);
        $this->assertSame('done', $results[2]['status']);
        $this->assertSame('done', $results[3]['status']);
    }

    public function test_run_deploy_generates_key_when_missing_and_redirects_to_login(): void
    {
        // Resolve the encrypter now (with the valid test key) so the request
        // middleware keeps working while the controller sees no APP_KEY.
        app('encrypter');
        config()->set('app.key', null);

        $runner = $this->mock(DeployService::class);
        $runner->shouldReceive('runArtisan')->with(['key:generate', '--force'])->once()->andReturn(['exit' => 0, 'output' => 'Application key set successfully.']);
        $runner->shouldReceive('runArtisan')->with(['migrate', '--force'])->once()->andReturn(['exit' => 0, 'output' => 'Migrated.']);
        $runner->shouldReceive('runArtisan')->with(['storage:link'])->once()->andReturn(['exit' => 0, 'output' => 'Linked.']);
        $runner->shouldReceive('runArtisan')->with(['optimize'])->once()->andReturn(['exit' => 0, 'output' => 'Cached.']);

        $user = $this->createUserWithChannel();

        $response = $this->actingAs($user)->post(route('settings.deploy.run'));

        // A freshly generated key invalidates every session — back to login.
        $response->assertRedirect(route('login'));
    }

    public function test_run_deploy_reports_failed_steps_honestly(): void
    {
        $runner = $this->mock(DeployService::class);
        $runner->shouldReceive('runArtisan')->with(['migrate', '--force'])->once()->andReturn(['exit' => 1, 'output' => 'SQLSTATE[HY000]: Connection refused']);
        $runner->shouldReceive('runArtisan')->with(['storage:link'])->once()->andReturn(['exit' => 0, 'output' => 'Linked.']);
        $runner->shouldReceive('runArtisan')->with(['optimize'])->once()->andReturn(['exit' => 0, 'output' => 'Cached.']);

        $user = $this->createUserWithChannel();

        $response = $this->actingAs($user)->post(route('settings.deploy.run'));

        $response->assertSessionHas('error');

        $results = session('deploy_results');
        $this->assertSame('failed', $results[1]['status']);
        $this->assertStringContainsString('Connection refused', $results[1]['detail']);
    }

    public function test_settings_page_shows_deployment_card(): void
    {
        $user = $this->createUserWithChannel();

        $response = $this->actingAs($user)->get(route('settings.index'));

        $response->assertOk();
        $response->assertSee('Deployment &amp; Setup', false);
        $response->assertSee('Run Deployment Setup', false);
        $response->assertSee('key:generate', false);
    }

    public function test_php_binary_detection_finds_a_usable_cli(): void
    {
        // The detected binary must actually run and report PHP >= 8.3 (the
        // shared-hosting trap: web SAPI newer than the CLI "php" on PATH).
        $binary = DeployService::phpBinary();
        $output = [];
        exec(escapeshellarg($binary).' -r '.escapeshellarg('echo PHP_VERSION;').' 2>&1', $output);
        $version = trim((string) ($output[0] ?? ''));

        $this->assertNotSame('', $version, 'Detected binary did not respond: '.$binary);
        $this->assertTrue(
            version_compare($version, '8.3', '>='),
            "Detected binary {$binary} reports PHP {$version} — expected >= 8.3"
        );
    }
}
