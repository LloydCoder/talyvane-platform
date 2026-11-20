<?php

namespace Tests\Feature;

use App\Install\InstallationLock;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class InstallationLockTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir().'/talyvane-lock-'.bin2hex(random_bytes(8));
        mkdir($this->basePath.'/storage/app/private', 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->basePath);

        parent::tearDown();
    }

    public function test_lock_is_signed_and_bound_to_database_installation_identity(): void
    {
        $lock = new InstallationLock($this->basePath, 'base64:'.base64_encode(random_bytes(32)));
        $created = $lock->create();

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $created['installation_id']);
        $this->assertTrue($lock->verify($created['installation_id'], $created['lock_hash']));
        $this->assertFalse($lock->verify('00000000-0000-4000-8000-000000000000', $created['lock_hash']));
        $this->assertFalse($lock->verify($created['installation_id'], str_repeat('0', 64)));
        $this->assertSame(0600, fileperms($lock->path()) & 0777);
    }

    public function test_lock_refuses_symlinked_storage_ancestors(): void
    {
        $storageApp = $this->basePath.'/storage/app';
        $realStorageApp = $this->basePath.'/storage/app-private-real';
        rename($storageApp, $realStorageApp);
        symlink($realStorageApp, $storageApp);

        $lock = new InstallationLock($this->basePath, 'application-key');

        $this->expectException(RuntimeException::class);
        $lock->create();
    }

    public function test_tampered_lock_is_rejected(): void
    {
        $lock = new InstallationLock($this->basePath, 'application-key');
        $created = $lock->create();
        $document = json_decode(file_get_contents($lock->path()), true);
        $document['payload']['installed_at'] = '2026-01-01T00:00:00Z';
        file_put_contents($lock->path(), json_encode($document));

        $this->assertFalse($lock->verify($created['installation_id'], $created['lock_hash']));
    }

    public function test_lock_creation_is_exclusive_and_cannot_overwrite_an_existing_lock(): void
    {
        $lock = new InstallationLock($this->basePath, 'application-key');
        $lock->create();

        $this->expectException(RuntimeException::class);
        $lock->create();
    }

    public function test_lock_can_only_be_removed_when_its_signature_and_identity_match(): void
    {
        $lock = new InstallationLock($this->basePath, 'application-key');
        $created = $lock->create();

        $this->assertFalse($lock->removeIfMatches('00000000-0000-4000-8000-000000000000', $created['lock_hash']));
        $this->assertFileExists($lock->path());
        $this->assertTrue($lock->removeIfMatches($created['installation_id'], $created['lock_hash']));
        $this->assertFileDoesNotExist($lock->path());
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
