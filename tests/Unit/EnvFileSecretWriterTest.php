<?php

namespace Tests\Unit;

use App\Services\EnvFileSecretWriter;
use Tests\TestCase;

class EnvFileSecretWriterTest extends TestCase
{
    private function temporaryEnvFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'talyvane-env-');
        file_put_contents($path, $contents);
        chmod($path, 0640);

        return $path;
    }

    public function test_it_updates_only_allowlisted_vault_credentials_and_preserves_other_environment_values(): void
    {
        $path = $this->temporaryEnvFile("APP_NAME=Talyvane\nSAFEGUARDING_VAULT_TOKEN=old-token\nMAIL_PASSWORD=mail-secret\n");
        try {
            app()->instance(EnvFileSecretWriter::class, new EnvFileSecretWriter($path));

            app(EnvFileSecretWriter::class)->putMany([
                'SAFEGUARDING_VAULT_TOKEN' => 'new-token-with-$-and-#-and-"quotes"',
                'SAFEGUARDING_VAULT_RETENTION_TOKEN' => 'separate-retention-token',
            ]);

            $contents = file_get_contents($path);
            $parsed = \Dotenv\Dotenv::parse($contents);

            $this->assertSame('Talyvane', $parsed['APP_NAME']);
            $this->assertSame('new-token-with-$-and-#-and-"quotes"', $parsed['SAFEGUARDING_VAULT_TOKEN']);
            $this->assertSame('separate-retention-token', $parsed['SAFEGUARDING_VAULT_RETENTION_TOKEN']);
            $this->assertSame('mail-secret', $parsed['MAIL_PASSWORD']);
            $this->assertSame(0600, fileperms($path) & 0777);
        } finally {
            @unlink($path);
        }
    }

    public function test_it_clears_only_allowlisted_vault_credentials(): void
    {
        $path = $this->temporaryEnvFile("APP_NAME=Talyvane\nSAFEGUARDING_VAULT_TOKEN=old-token\nSAFEGUARDING_VAULT_RETENTION_TOKEN=old-retention\n");
        try {
            $writer = new EnvFileSecretWriter($path);
            $writer->clearMany(['SAFEGUARDING_VAULT_TOKEN', 'SAFEGUARDING_VAULT_RETENTION_TOKEN']);

            $parsed = \Dotenv\Dotenv::parse(file_get_contents($path));
            $this->assertSame('', $parsed['SAFEGUARDING_VAULT_TOKEN']);
            $this->assertSame('', $parsed['SAFEGUARDING_VAULT_RETENTION_TOKEN']);
            $this->assertSame('Talyvane', $parsed['APP_NAME']);
        } finally {
            @unlink($path);
        }
    }

    public function test_it_refuses_to_write_when_runtime_credentials_are_overridden_outside_dotenv(): void
    {
        $path = $this->temporaryEnvFile("SAFEGUARDING_VAULT_TOKEN=file-token\nSAFEGUARDING_VAULT_RETENTION_TOKEN=file-retention\n");
        try {
            $this->expectException(\\RuntimeException::class);
            (new EnvFileSecretWriter($path))->assertRuntimeMatches([
                'SAFEGUARDING_VAULT_TOKEN' => 'injected-runtime-token',
                'SAFEGUARDING_VAULT_RETENTION_TOKEN' => 'file-retention',
            ]);
        } finally {
            @unlink($path);
        }
    }

    public function test_it_refuses_duplicate_secret_keys_in_the_environment_file(): void
    {
        $path = $this->temporaryEnvFile("SAFEGUARDING_VAULT_TOKEN=first\nSAFEGUARDING_VAULT_TOKEN=second\n");
        try {
            $this->expectException(\RuntimeException::class);
            (new EnvFileSecretWriter($path))->putMany(['SAFEGUARDING_VAULT_TOKEN' => 'replacement-token']);
        } finally {
            @unlink($path);
        }
    }

    public function test_it_rejects_multiline_secret_values(): void
    {
        $path = $this->temporaryEnvFile("APP_NAME=Talyvane\n");
        try {
            $this->expectException(\InvalidArgumentException::class);
            (new EnvFileSecretWriter($path))->putMany(['SAFEGUARDING_VAULT_TOKEN' => "first-line\nsecond-line"]);
        } finally {
            @unlink($path);
        }
    }

    public function test_it_refuses_to_modify_a_symlinked_environment_file(): void
    {
        $target = $this->temporaryEnvFile("APP_NAME=Talyvane\n");
        $link = $target.'-link';
        try {
            symlink($target, $link);
            $this->expectException(\RuntimeException::class);
            (new EnvFileSecretWriter($link))->putMany(['SAFEGUARDING_VAULT_TOKEN' => 'replacement-token']);
        } finally {
            @unlink($link);
            @unlink($target);
        }
    }
}
