<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstallerPreflightTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        @unlink(storage_path('app/private/INSTALLER-ACCESS-KEY.txt'));
        @unlink(storage_path('app/private/talyvane.install.lock'));
        parent::tearDown();
    }

    public function test_installer_screen_displays_hosting_preflight_before_configuration(): void
    {
        config(['talyvane.installer.enforce' => false]);

        $this->get('/install')
            ->assertOk()
            ->assertSee('FIRST-TIME SETUP')
            ->assertSee('Hosting preflight')
            ->assertSee('PHP 8.3 or later');
    }

    public function test_installer_post_cannot_bypass_https_and_private_document_root_preflight(): void
    {
        config(['talyvane.installer.enforce' => false]);

        $installerPage = $this->get('/install')->assertOk();
        preg_match('/name="installer_token" value="([A-Za-z0-9_-]{43})"/', $installerPage->getContent(), $tokenMatch);
        $this->assertNotEmpty($tokenMatch[1] ?? null);

        $this->post('/install', [
            'installer_token' => $tokenMatch[1],
            'app_name' => 'Talyvane',
            'app_url' => 'https://localhost',
            'db_host' => 'localhost',
            'db_port' => 3306,
            'db_database' => 'talyvane_test',
            'db_username' => 'talyvane',
            'db_password' => 'test-password',
            'mail_scheme' => 'smtp',
            'mail_host' => 'mail.example.test',
            'mail_port' => 587,
            'mail_username' => 'installer@example.test',
            'mail_password' => 'test-password',
            'mail_from_address' => 'installer@example.test',
            'mail_from_name' => 'Talyvane',
            'admin_name' => 'Deployment Super Admin',
            'admin_email' => 'admin@example.test',
            'recovery_email' => 'recovery@example.test',
        ])->assertSessionHasErrors('installer');
    }
}
