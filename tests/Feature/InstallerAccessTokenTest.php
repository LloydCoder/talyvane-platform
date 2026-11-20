<?php

namespace Tests\Feature;

use App\Install\InstallerAccessToken;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class InstallerAccessTokenTest extends TestCase
{
    private string $temporaryRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporaryRoot = sys_get_temp_dir().'/talyvane-installer-key-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->temporaryRoot.'/storage/app/private', 0700, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temporaryRoot);
        parent::tearDown();
    }

    public function test_key_is_cryptographically_random_private_idempotent_and_consumable(): void
    {
        $access = new InstallerAccessToken($this->temporaryRoot);
        $access->ensure();

        $token = trim((string) file_get_contents($access->path()));
        $this->assertSame(43, strlen($token));
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/', $token);
        $this->assertTrue($access->verify($token));
        $this->assertFalse($access->verify(str_repeat('a', 43)));

        $permissions = fileperms($access->path());
        $this->assertSame(0600, $permissions & 0777);

        $access->ensure();
        $this->assertSame($token, trim((string) file_get_contents($access->path())));

        $this->assertTrue($access->consume());
        $this->assertFileDoesNotExist($access->path());
        $this->assertFalse($access->verify($token));
    }

    public function test_completed_installation_never_generates_a_new_access_key(): void
    {
        file_put_contents($this->temporaryRoot.'/storage/app/private/talyvane.install.lock', 'installed');
        $access = new InstallerAccessToken($this->temporaryRoot);

        $access->ensure();

        $this->assertFileDoesNotExist($access->path());
        $this->assertFalse($access->isAvailable());
    }
}
