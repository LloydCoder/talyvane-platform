<?php

namespace Tests\Feature;

use App\Install\EnvironmentWriter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class EnvironmentWriterTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir().'/talyvane-env-writer-'.bin2hex(random_bytes(8));
        mkdir($this->basePath, 0700, true);
        file_put_contents($this->basePath.'/.env', "APP_ENV=production\nDB_PASSWORD=old\nUNRELATED=value\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->basePath.'/.env');
        @rmdir($this->basePath);
        parent::tearDown();
    }

    public function test_writer_updates_only_allow_listed_values_and_escapes_dotenv_syntax(): void
    {
        (new EnvironmentWriter($this->basePath))->update([
            'DB_PASSWORD' => 'p@ss"word\\path$HOME#fragment',
            'DB_HOST' => 'localhost',
        ]);

        $contents = file_get_contents($this->basePath.'/.env');
        $this->assertStringContainsString('DB_PASSWORD="p@ss\\"word\\\\path\\$HOME#fragment"', $contents);
        $this->assertStringContainsString('DB_HOST="localhost"', $contents);
        $this->assertStringContainsString('UNRELATED=value', $contents);
        $this->assertSame(0600, fileperms($this->basePath.'/.env') & 0777);
    }

    public function test_writer_rejects_unknown_keys_and_control_characters(): void
    {
        $writer = new EnvironmentWriter($this->basePath);

        try {
            $writer->update(['APP_AUTOEXEC' => 'php artisan migrate']);
            $this->fail('Unknown environment keys must be rejected.');
        } catch (RuntimeException) {
            $this->assertSame("APP_ENV=production\nDB_PASSWORD=old\nUNRELATED=value\n", file_get_contents($this->basePath.'/.env'));
        }

        $this->expectException(RuntimeException::class);
        $writer->update(['DB_PASSWORD' => "secret\nAPP_DEBUG=true"]);
    }

    public function test_writer_rejects_duplicate_keys_and_symlinked_environment_files(): void
    {
        file_put_contents($this->basePath.'/.env', "APP_ENV=production\nDB_PASSWORD=one\nDB_PASSWORD=two\n");
        try {
            (new EnvironmentWriter($this->basePath))->update(['DB_PASSWORD' => 'new']);
            $this->fail('Duplicate keys must be rejected.');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }

        unlink($this->basePath.'/.env');
        $target = $this->basePath.'/outside';
        file_put_contents($target, 'APP_ENV=production');
        symlink($target, $this->basePath.'/.env');

        $this->expectException(RuntimeException::class);
        (new EnvironmentWriter($this->basePath))->update(['DB_HOST' => 'localhost']);
    }
}
