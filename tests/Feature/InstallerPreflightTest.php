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
        @unlink(storage_path('app/private/talyvane.install.pending.json'));
        @unlink(storage_path('app/private/talyvane.install.lock'));
        parent::tearDown();
    }

    public function test_installer_screen_displays_hosting_preflight_before_configuration(): void
    {
        config(['talyvane.installer.enforce' => false]);

        $this->get('/install')
            ->assertOk()
            ->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertSee('FIRST-TIME SETUP')
            ->assertSee('Hosting preflight')
            ->assertSee('PHP 8.3 or later')
            ->assertSee('action="/install"', false)
            ->assertSee('GD WebP image support')
            ->assertSee('Session storage is writable');
    }

    public function test_preflight_rejects_private_application_files_inside_public_document_root(): void
    {
        config(['talyvane.installer.enforce' => false]);

        $privateDirectory = public_path('vendor');
        mkdir($privateDirectory, 0700, true);

        try {
            $this->get('/install')
                ->assertOk()
                ->assertSee('Document root is isolated and serves only public files');
        } finally {
            @rmdir($privateDirectory);
        }
    }

    public function test_preflight_rejects_symlinked_session_storage(): void
    {
        config(['talyvane.installer.enforce' => false]);

        $sessions = storage_path('framework/sessions');
        $backup = storage_path('framework/sessions-installer-test-backup');
        $target = sys_get_temp_dir().'/talyvane-installer-session-'.bin2hex(random_bytes(6));
        mkdir($target, 0700, true);
        rename($sessions, $backup);
        symlink($target, $sessions);

        try {
            $this->get('/install')
                ->assertOk()
                ->assertSee('Session storage is writable');
        } finally {
            @unlink($sessions);
            rename($backup, $sessions);
            @rmdir($target);
        }
    }

    public function test_expired_installer_session_cannot_submit_configuration(): void
    {
        config(['talyvane.installer.enforce' => false]);

        $page = $this->get('/install')->assertOk();
        $privateInstallerKey = trim((string) file_get_contents(storage_path('app/private/INSTALLER-ACCESS-KEY.txt')));
        $this->assertMatchesRegularExpression('/\\A[A-Za-z0-9_-]{43}\\z/D', $privateInstallerKey);
        $this->assertStringNotContainsString($privateInstallerKey, $page->getContent());
        $this->app['session.store']->put('installer.started_at', time() - 901);

        $this->post('/install', [
            'installer_token' => $privateInstallerKey,
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

    public function test_installer_post_cannot_bypass_https_and_private_document_root_preflight(): void
    {
        config(['talyvane.installer.enforce' => false]);

        $installerPage = $this->get('/install')->assertOk();
        $privateInstallerKey = trim((string) file_get_contents(storage_path('app/private/INSTALLER-ACCESS-KEY.txt')));
        $this->assertMatchesRegularExpression('/\\A[A-Za-z0-9_-]{43}\\z/D', $privateInstallerKey);
        $this->assertStringNotContainsString($privateInstallerKey, $installerPage->getContent());

        $this->post('/install', [
            'installer_token' => $privateInstallerKey,
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
    public function test_validation_errors_never_flash_installer_secrets_into_session_input(): void
    {
        config(['talyvane.installer.enforce' => false]);

        $this->get('/install')->assertOk();

        $this->from('/install')->post('/install', [
            'installer_token' => str_repeat('A', 43),
            'app_name' => '',
            'app_url' => 'https://localhost',
            'db_host' => 'localhost',
            'db_port' => 3306,
            'db_database' => 'talyvane_test',
            'db_username' => 'talyvane',
            'db_password' => 'db-secret-must-not-flash',
            'mail_scheme' => 'smtp',
            'mail_host' => 'mail.example.test',
            'mail_port' => 587,
            'mail_username' => 'installer@example.test',
            'mail_password' => 'smtp-secret-must-not-flash',
            'mail_from_address' => 'installer@example.test',
            'mail_from_name' => 'Talyvane',
            'admin_name' => 'Deployment Super Admin',
            'admin_email' => 'admin@example.test',
            'recovery_email' => 'recovery@example.test',
            'primary_code' => '123456',
            'recovery_code' => '654321',
        ])->assertSessionHasErrors('app_name')
            ->assertSessionMissing('_old_input.db_password')
            ->assertSessionMissing('_old_input.mail_password')
            ->assertSessionMissing('_old_input.installer_token')
            ->assertSessionMissing('_old_input.primary_code')
            ->assertSessionMissing('_old_input.recovery_code');
    }

    public function test_installer_configuration_endpoint_is_rate_limited(): void
    {
        config(['talyvane.installer.enforce' => false]);

        $this->get('/install')->assertOk();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/install', [])->assertSessionHasErrors();
        }

        $this->post('/install', [])->assertTooManyRequests();
    }

    public function test_installer_configuration_post_requires_a_valid_csrf_token(): void
    {
        $this->withMiddleware();
        app()->detectEnvironment(fn () => 'production');
        config(['talyvane.installer.enforce' => false]);
        $this->withServerVariables([
            'HTTPS' => 'on',
            'HTTP_HOST' => 'localhost',
            'SERVER_PORT' => 443,
            'DOCUMENT_ROOT' => realpath(public_path()),
        ]);

        $this->get('https://localhost/install')->assertOk();

        $this->post('https://localhost/install', [])->assertStatus(419);
    }

}
