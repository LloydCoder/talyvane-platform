<?php

namespace Tests\Feature;

use App\Install\PublicPathResolver;
use PHPUnit\Framework\TestCase;

class PublicPathResolverTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir().'/talyvane-public-path-'.bin2hex(random_bytes(8));
        mkdir($this->basePath.'/application/public', 0700, true);
        mkdir($this->basePath.'/public-root', 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->basePath);
        parent::tearDown();
    }

    public function test_custom_cpanel_document_root_is_selected_for_public_assets(): void
    {
        $resolved = PublicPathResolver::resolve(
            $this->basePath.'/application/public',
            $this->basePath.'/public-root',
        );

        $this->assertSame(realpath($this->basePath.'/public-root'), $resolved);
    }

    public function test_default_laravel_public_root_is_preserved(): void
    {
        $resolved = PublicPathResolver::resolve(
            $this->basePath.'/application/public',
            $this->basePath.'/application/public',
        );

        $this->assertSame(realpath($this->basePath.'/application/public'), $resolved);
    }

    public function test_missing_document_root_fails_closed_without_overriding_public_path(): void
    {
        $this->assertNull(PublicPathResolver::resolve(
            $this->basePath.'/application/public',
            $this->basePath.'/missing',
        ));
        $this->assertNull(PublicPathResolver::resolve(
            $this->basePath.'/application/public',
            '',
        ));
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
