<?php

namespace Tests\Feature;

use App\Install\InstallationDirectoryRemover;
use PHPUnit\Framework\TestCase;

class InstallationDirectoryRemoverTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir().'/talyvane-install-remove-'.bin2hex(random_bytes(8));
        mkdir($this->basePath.'/install', 0700, true);
        foreach (['bootstrap.php', '.htaccess', 'README.md', 'RELEASE-MANIFEST.json'] as $file) {
            file_put_contents($this->basePath.'/install/'.$file, 'release-owned');
        }
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->basePath);
        parent::tearDown();
    }

    public function test_remover_deletes_only_the_known_release_owned_directory_contents(): void
    {
        $this->assertTrue((new InstallationDirectoryRemover($this->basePath))->remove());
        $this->assertDirectoryDoesNotExist($this->basePath.'/install');
    }

    public function test_remover_refuses_to_delete_unrecognized_files_or_subdirectories(): void
    {
        file_put_contents($this->basePath.'/install/customer-data.txt', 'must survive');
        $this->assertFalse((new InstallationDirectoryRemover($this->basePath))->remove());
        $this->assertFileExists($this->basePath.'/install/customer-data.txt');
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
