<?php

namespace Tests\Feature;

use App\Install\InstallerPendingState;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class InstallerPendingStateTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir().'/talyvane-pending-state-'.bin2hex(random_bytes(8));
        mkdir($this->basePath.'/storage/app/private', 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->basePath);
        parent::tearDown();
    }

    public function test_pending_installation_is_exclusive_and_bound_to_a_random_nonce(): void
    {
        $state = new InstallerPendingState($this->basePath);
        $nonce = $state->acquire();

        $this->assertTrue($state->verify($nonce));
        $this->assertFalse($state->verify(str_repeat('a', 43)));

        try {
            $state->acquire();
            $this->fail('A second installation must not begin while verification is pending.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('already in progress', $exception->getMessage());
        }

        $this->assertTrue($state->release($nonce));
        $this->assertFalse($state->verify($nonce));
    }

    public function test_expired_pending_state_can_be_replaced(): void
    {
        $state = new InstallerPendingState($this->basePath);
        $oldNonce = str_repeat('b', 43);
        file_put_contents($state->path(), json_encode([
            'schema_version' => 1,
            'nonce_hash' => hash('sha256', $oldNonce),
            'expires_at' => time() - 1,
        ], JSON_THROW_ON_ERROR));
        chmod($state->path(), 0600);

        $newNonce = $state->acquire();

        $this->assertNotSame($oldNonce, $newNonce);
        $this->assertTrue($state->verify($newNonce));
        $this->assertFalse($state->verify($oldNonce));
    }

    public function test_malformed_pending_state_fails_closed(): void
    {
        $state = new InstallerPendingState($this->basePath);
        file_put_contents($state->path(), '{"schema_version":1,"nonce_hash":"invalid","expires_at":9999999999}');

        $this->expectException(RuntimeException::class);
        $state->acquire();
    }

    public function test_pending_state_refuses_symlinked_state_file(): void
    {
        $state = new InstallerPendingState($this->basePath);
        $target = $this->basePath.'/outside-state';
        file_put_contents($target, '{}');
        symlink($target, $state->path());

        $this->expectException(RuntimeException::class);
        $state->acquire();
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
