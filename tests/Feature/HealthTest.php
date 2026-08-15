<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

class HealthTest extends TestCase
{
    use RefreshDatabase;

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

    // -------------------------------------------------------------------
    // Laravel route: /health
    // -------------------------------------------------------------------

    public function test_health_is_public_and_reports_all_checks(): void
    {
        $response = $this->get(route('health'));

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('status', 'healthy');
        $response->assertJsonPath('app.env', config('app.env'));
        $response->assertJsonPath('php.version', PHP_VERSION);
        $response->assertJsonPath('php.ok', version_compare(PHP_VERSION, '8.3', '>='));
        $response->assertJsonPath('database.ok', true);
        $response->assertJsonPath('database.driver', config('database.default'));
        $response->assertJsonPath('migrations.ok', true);
        $this->assertSame([], $response->json('migrations.pending'));
        $response->assertJsonPath('storage.ok', true);
        $response->assertJsonPath('video_disk.ok', true);
        $response->assertJsonPath('video_disk.disk', config('filesystems.video_disk'));
        // The session middleware appends ", private" on top of our no-store.
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_health_reports_database_failure_as_503(): void
    {
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.host' => '127.0.0.1',
            'database.connections.mysql.port' => '1',
            'database.connections.mysql.database' => 'nonexistent',
            'database.connections.mysql.username' => 'nouser',
            'database.connections.mysql.password' => '',
            // Fail fast instead of waiting out Windows' TCP timeout (~13s).
            'database.connections.mysql.options' => [PDO::ATTR_TIMEOUT => 3],
        ]);

        try {
            $response = $this->get(route('health'));

            $response->assertStatus(503);
            $response->assertJsonPath('ok', false);
            $response->assertJsonPath('status', 'degraded');
            $response->assertJsonPath('database.ok', false);
            $response->assertJsonPath('database.connected', false);
            $this->assertNotEmpty($response->json('database.error'));
        } finally {
            // Restore the original connection before teardown so RefreshDatabase
            // never tries to touch the deliberately-dead mysql connection.
            config(['database.default' => 'sqlite']);
        }
    }

    public function test_health_flags_pending_migrations_as_degraded(): void
    {
        DB::table('migrations')->where('migration', 'like', '2026_08_15_110000%')->delete();

        $response = $this->get(route('health'));

        $response->assertStatus(503);
        $response->assertJsonPath('ok', false);
        $response->assertJsonPath('migrations.ok', false);
        $this->assertContains(
            '2026_08_15_110000_add_gemini_enabled_to_channels',
            $response->json('migrations.pending')
        );
    }

    // -------------------------------------------------------------------
    // Standalone probe: public/health.php (works without Laravel)
    // -------------------------------------------------------------------

    private function buildStandaloneFixture(array $files, string $envContent): string
    {
        $base = sys_get_temp_dir().'/omnishorts-health-test-'.uniqid();
        $this->fixture = $base;

        foreach (['public', 'public/storage', 'storage/logs', 'storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views', 'storage/app/public'] as $dir) {
            mkdir($base.'/'.$dir, 0777, true);
        }
        copy(base_path('public/health.php'), $base.'/public/health.php');
        file_put_contents($base.'/.env', $envContent);

        foreach ($files as $path => $content) {
            $full = $base.'/'.$path;
            if (! is_dir(dirname($full))) {
                mkdir(dirname($full), 0777, true);
            }
            file_put_contents($full, $content);
        }

        return $base;
    }

    private function runStandalone(string $fixture): array
    {
        if (in_array('exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
            $this->markTestSkipped('exec() is disabled in this environment.');
        }

        $output = [];
        $code = 0;
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($fixture.'/public/health.php').' 2>&1', $output, $code);

        return [implode("\n", $output), $code];
    }

    public function test_standalone_health_reports_fully_healthy_app(): void
    {
        $f = $this->buildStandaloneFixture([
            'database/migrations/0001_01_01_000000_fake_users.php' => '<?php // fake',
        ], "APP_KEY=base64:keep-secret-value-123\nAPP_ENV=production\nAPP_DEBUG=false\nDB_CONNECTION=sqlite\nDB_DATABASE=db.sqlite\n");

        $pdo = new PDO('sqlite:'.$f.'/db.sqlite');
        $pdo->exec('CREATE TABLE migrations (migration TEXT PRIMARY KEY, batch INTEGER NOT NULL)');
        $pdo->exec("INSERT INTO migrations (migration, batch) VALUES ('0001_01_01_000000_fake_users', 1)");

        [$json] = $this->runStandalone($f);

        $data = json_decode($json, true);
        $this->assertIsArray($data);
        $this->assertTrue($data['ok']);
        $this->assertSame('healthy', $data['status']);
        $this->assertTrue($data['checks']['database']['ok']);
        $this->assertSame('sqlite', $data['checks']['database']['driver']);
        $this->assertTrue($data['checks']['migrations']['ok']);
        $this->assertSame([], $data['checks']['migrations']['pending']);
        $this->assertTrue($data['checks']['storage']['ok']);
        $this->assertTrue($data['checks']['video_disk']['ok']);
        $this->assertSame('public', $data['checks']['video_disk']['disk']);
        $this->assertFalse($data['checks']['env']['app_debug']);
        // The .env secret never leaks into the response.
        $this->assertStringNotContainsString('keep-secret-value-123', $json);
    }

    public function test_standalone_health_flags_missing_migrations_table(): void
    {
        $f = $this->buildStandaloneFixture([], "APP_KEY=base64:key-123\nDB_CONNECTION=sqlite\nDB_DATABASE=db.sqlite\n");

        [$json] = $this->runStandalone($f);

        $data = json_decode($json, true);
        $this->assertIsArray($data);
        $this->assertFalse($data['ok']);
        $this->assertSame('degraded', $data['status']);
        $this->assertFalse($data['checks']['migrations']['table_exists']);
    }

    public function test_standalone_health_never_leaks_credentials_when_db_is_down(): void
    {
        $f = $this->buildStandaloneFixture([], "APP_KEY=base64:topsecret-app-key-123\nAPP_ENV=production\nDB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nDB_PORT=1\nDB_DATABASE=secretdb\nDB_USERNAME=root\nDB_PASSWORD=SuperSecretPw!\n");

        [$json] = $this->runStandalone($f);

        $data = json_decode($json, true);
        $this->assertIsArray($data);
        $this->assertFalse($data['checks']['database']['ok']);
        $this->assertNotEmpty($data['checks']['database']['error']);
        // Credentials never appear in the response, even inside the error text.
        $this->assertStringNotContainsString('SuperSecretPw!', $json);
        $this->assertStringNotContainsString('topsecret-app-key-123', $json);
    }

    public function test_standalone_health_flags_ftp_config_without_host(): void
    {
        $f = $this->buildStandaloneFixture([], "APP_KEY=base64:key-123\nVIDEO_DISK=ftp\nDB_CONNECTION=sqlite\nDB_DATABASE=db.sqlite\n");

        [$json] = $this->runStandalone($f);

        $data = json_decode($json, true);
        $this->assertIsArray($data);
        $this->assertFalse($data['checks']['video_disk']['ok']);
        $this->assertSame('ftp', $data['checks']['video_disk']['disk']);
        $this->assertStringContainsString('FTP_HOST', $data['checks']['video_disk']['detail']);
        $this->assertFalse($data['ok']);
    }
}
