<?php

namespace Tests\Feature;

use App\Install\BootstrapEnvironment;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class InstallerBootstrapEnvironmentTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir().'/talyvane-installer-'.bin2hex(random_bytes(8));
        mkdir($this->basePath, 0700, true);
        mkdir($this->basePath.'/storage/app/private', 0700, true);
        file_put_contents($this->basePath.'/.env.example', implode("\n", [
            'APP_NAME=Talyvane',
            'APP_ENV=local',
            'APP_KEY=',
            'APP_DEBUG=false',
            'APP_URL=http://localhost',
            'DB_CONNECTION=mysql',
            'DB_HOST=127.0.0.1',
            'DB_DATABASE=talyvane',
            'DB_USERNAME=talyvane',
            'DB_PASSWORD=',
            'SESSION_DRIVER=file',
            'SESSION_ENCRYPT=true',
            'SESSION_SECURE_COOKIE=false',
            'CACHE_STORE=file',
            'QUEUE_CONNECTION=sync',
            '',
        ]));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->basePath);

        parent::tearDown();
    }

    public function test_first_request_creates_a_unique_production_safe_bootstrap_environment(): void
    {
        $created = (new BootstrapEnvironment($this->basePath))->ensure();

        $this->assertTrue($created);
        $environment = file_get_contents($this->basePath.'/.env');
        preg_match('/^APP_KEY=base64:([A-Za-z0-9+\/=]+)$/m', $environment, $matches);

        $this->assertNotEmpty($matches[1] ?? null);
        $this->assertSame(32, strlen(base64_decode($matches[1], true)));
        $this->assertStringContainsString("APP_ENV=production", $environment);
        $this->assertStringContainsString("APP_DEBUG=false", $environment);
        $this->assertStringContainsString("SESSION_DRIVER=file", $environment);
        $this->assertStringContainsString("SESSION_ENCRYPT=true", $environment);
        $this->assertStringContainsString("SESSION_SECURE_COOKIE=true", $environment);
        $this->assertStringContainsString("CACHE_STORE=file", $environment);
        $this->assertStringContainsString("QUEUE_CONNECTION=sync", $environment);
        $this->assertStringContainsString("TALYVANE_INSTALLER_ENFORCE=true", $environment);
        $this->assertSame(0600, fileperms($this->basePath.'/.env') & 0777);
    }

    public function test_existing_application_key_is_preserved_without_rewriting_environment(): void
    {
        $environment = "APP_ENV=production\nAPP_KEY=base64:".base64_encode(random_bytes(32))."\nDB_PASSWORD=keep-this-value\n";
        file_put_contents($this->basePath.'/.env', $environment);

        $created = (new BootstrapEnvironment($this->basePath))->ensure();

        $this->assertFalse($created);
        $this->assertSame($environment, file_get_contents($this->basePath.'/.env'));
    }

    public function test_bootstrap_rejects_symlinked_session_storage_before_framework_boot(): void
    {
        $sessions = $this->basePath.'/storage/framework/sessions';
        mkdir($this->basePath.'/storage/framework', 0700, true);
        $target = $this->basePath.'/outside-sessions';
        mkdir($target, 0700, true);
        symlink($target, $sessions);

        $this->expectException(RuntimeException::class);
        (new BootstrapEnvironment($this->basePath))->ensure();
    }

    public function test_existing_application_key_must_be_valid_and_environment_permissions_are_restricted(): void
    {
        $environment = "APP_ENV=production\nAPP_KEY=not-a-valid-key\n";
        file_put_contents($this->basePath.'/.env', $environment);

        try {
            (new BootstrapEnvironment($this->basePath))->ensure();
            $this->fail('Malformed application keys must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('application key is invalid', $exception->getMessage());
        }

        $environment = "APP_ENV=production\nAPP_KEY=base64:".base64_encode(random_bytes(32))."\n";
        file_put_contents($this->basePath.'/.env', $environment);
        chmod($this->basePath.'/.env', 0644);

        $this->assertFalse((new BootstrapEnvironment($this->basePath))->ensure());
        $this->assertSame(0600, fileperms($this->basePath.'/.env') & 0777);
    }

    public function test_blank_application_key_is_filled_without_losing_database_configuration(): void
    {
        $environment = "APP_ENV=local\nAPP_KEY=\nAPP_DEBUG=true\nDB_PASSWORD=database-secret\n";
        file_put_contents($this->basePath.'/.env', $environment);

        $created = (new BootstrapEnvironment($this->basePath))->ensure();
        $result = file_get_contents($this->basePath.'/.env');

        $this->assertTrue($created);
        $this->assertStringContainsString('DB_PASSWORD=database-secret', $result);
        $this->assertStringContainsString('APP_ENV=production', $result);
        $this->assertStringContainsString('APP_DEBUG=false', $result);
        $this->assertMatchesRegularExpression('/^APP_KEY=base64:[A-Za-z0-9+\/=]+$/m', $result);
    }

    public function test_installed_lock_prevents_bootstrap_environment_mutation(): void
    {
        $environment = "APP_ENV=production\nAPP_KEY=base64:".base64_encode(random_bytes(32))."\nDB_PASSWORD=preserve-me\n";
        file_put_contents($this->basePath.'/.env', $environment);
        file_put_contents($this->basePath.'/storage/app/private/talyvane.install.lock', 'signed-lock-placeholder');

        $created = (new BootstrapEnvironment($this->basePath))->ensure();

        $this->assertFalse($created);
        $this->assertSame($environment, file_get_contents($this->basePath.'/.env'));
    }

    public function test_environment_symlink_is_rejected(): void
    {
        $target = $this->basePath.'/outside-env';
        file_put_contents($target, 'APP_KEY=attacker-controlled');
        symlink($target, $this->basePath.'/.env');

        $this->expectException(RuntimeException::class);
        (new BootstrapEnvironment($this->basePath))->ensure();
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path) || is_link($path)) {
            @unlink($path);
            return;
        }

        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $target = $path.'/'.$entry;
            if (is_dir($target) && ! is_link($target)) {
                $this->removeTree($target);
            } else {
                @unlink($target);
            }
        }

        @rmdir($path);
    }
}
