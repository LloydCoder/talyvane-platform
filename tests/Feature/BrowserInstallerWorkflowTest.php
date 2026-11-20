<?php

namespace Tests\Feature;

use App\Install\EnvironmentWriter;
use App\Install\InstallationDirectoryRemover;
use App\Install\InstallationLock;
use App\Models\User;
use App\Notifications\SuperAdminEmailVerificationCode;
use App\Services\RecoveryCodeGenerator;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class BrowserInstallerWorkflowTest extends TestCase
{
    private string $temporaryRoot;
    private string $originalDefaultConnection;

    protected function setUp(): void
    {
        parent::setUp();
        // Throttling is covered by InstallerPreflightTest. Keep this
        // end-to-end workflow independent of shared file-cache limiter state.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $this->originalDefaultConnection = (string) config('database.default');

        $this->temporaryRoot = sys_get_temp_dir().'/talyvane-installer-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->temporaryRoot, 0700, true);

        $environmentRoot = $this->temporaryRoot.'/environment';
        File::ensureDirectoryExists($environmentRoot, 0700, true);
        file_put_contents($environmentRoot.'/.env', 'APP_KEY='.config('app.key').PHP_EOL);

        config([
            'database.default' => 'mysql',
            'database.connections.mysql' => [
                'driver' => 'mysql',
                'host' => '127.0.0.1',
                'port' => 3306,
                'database' => 'talyvane_test',
                'username' => 'talyvane',
                'password' => 'test-password',
                'unix_socket' => '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'prefix_indexes' => true,
                'strict' => true,
                'engine' => null,
                'options' => [],
            ],
            'app.env' => 'production',
            'app.debug' => false,
            'app.url' => 'https://agency.example.test',
            'talyvane.installer.enforce' => false,
            'session.driver' => 'file',
            'session.encrypt' => true,
            'session.secure' => true,
            'session.http_only' => true,
            'session.same_site' => 'lax',
            'cache.default' => 'file',
            'queue.default' => 'sync',
        ]);
        DB::purge('mysql');

        // This feature class owns the dedicated MySQL CI database. Reset it
        // before every test so completed installations and foreign-schema
        // fixtures cannot leak between test cases.
        $connection = DB::connection('mysql');
        $schema = $connection->getSchemaBuilder();
        $existingTables = $schema->getTableListing();
        if ($existingTables !== []) {
            $connection->statement('SET FOREIGN_KEY_CHECKS=0');
            try {
                foreach ($existingTables as $table) {
                    $schema->drop($table);
                }
            } finally {
                $connection->statement('SET FOREIGN_KEY_CHECKS=1');
            }
        }

        app()->instance(EnvironmentWriter::class, new EnvironmentWriter($environmentRoot));

        $packageRoot = $this->temporaryRoot.'/package';
        $installerDirectory = $packageRoot.'/install';
        File::ensureDirectoryExists($installerDirectory, 0700, true);
        file_put_contents($installerDirectory.'/bootstrap.php', '<?php');
        file_put_contents($installerDirectory.'/.htaccess', 'Require all denied');
        file_put_contents($installerDirectory.'/README.md', 'Private installer directory');
        file_put_contents($installerDirectory.'/RELEASE-MANIFEST.json', '{}');
        app()->instance(InstallationDirectoryRemover::class, new InstallationDirectoryRemover($packageRoot));

        $this->withServerVariables([
            'HTTPS' => 'on',
            'HTTP_HOST' => 'agency.example.test',
            'SERVER_PORT' => 443,
            'DOCUMENT_ROOT' => realpath(public_path()),
        ]);

        Notification::fake();
    }

    protected function tearDown(): void
    {
        @unlink(storage_path('app/private/talyvane.install.lock'));
        @unlink(storage_path('app/private/INSTALLER-ACCESS-KEY.txt'));
        @unlink(storage_path('app/private/talyvane.install.pending.json'));

        DB::disconnect('mysql');
        DB::purge('mysql');
        config(['database.default' => $this->originalDefaultConnection]);
        File::deleteDirectory($this->temporaryRoot);

        parent::tearDown();
    }

    public function test_installer_refuses_foreign_non_empty_database_before_running_migrations(): void
    {
        $connection = DB::connection('mysql');
        $connection->statement('CREATE TABLE platform_control (id TINYINT PRIMARY KEY, super_admin_user_id BIGINT NULL, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL)');
        $connection->statement('CREATE TABLE migrations (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, migration VARCHAR(255) NOT NULL, batch INT NOT NULL)');
        $connection->statement('CREATE TABLE foreign_customer_data (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, payload TEXT NULL)');
        $connection->table('platform_control')->insert([
            'id' => 1,
            'super_admin_user_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $connection->table('migrations')->insert([
            'migration' => '2026_11_19_000024_create_platform_control_table',
            'batch' => 1,
        ]);

        $this->get('https://agency.example.test/install')->assertOk();
        $privateInstallerKey = trim((string) file_get_contents(storage_path('app/private/INSTALLER-ACCESS-KEY.txt')));

        $this->post('https://agency.example.test/install', [
            'installer_token' => $privateInstallerKey,
            'app_name' => 'Talyvane Agency',
            'app_url' => 'https://agency.example.test',
            'db_host' => '127.0.0.1',
            'db_port' => 3306,
            'db_database' => 'talyvane_test',
            'db_username' => 'talyvane',
            'db_password' => 'test-password',
            'mail_scheme' => 'smtp',
            'mail_host' => 'smtp.example.test',
            'mail_port' => 587,
            'mail_username' => 'installer@example.test',
            'mail_password' => 'test-mail-password',
            'mail_from_address' => 'installer@example.test',
            'mail_from_name' => 'Talyvane Agency',
            'admin_name' => 'Deployment Super Admin',
            'admin_email' => 'admin@example.test',
            'recovery_email' => 'recovery@example.test',
        ])->assertSessionHasErrors('installer');

        $this->assertFalse($connection->getSchemaBuilder()->hasTable('users'));
        $this->assertTrue($connection->getSchemaBuilder()->hasTable('foreign_customer_data'));
        $this->assertSame(1, $connection->table('migrations')->count());
    }

    public function test_installer_refuses_recognised_database_that_already_contains_users(): void
    {
        $connection = DB::connection('mysql');
        $connection->statement('CREATE TABLE platform_control (id TINYINT PRIMARY KEY, super_admin_user_id BIGINT NULL, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL)');
        $connection->statement('CREATE TABLE migrations (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, migration VARCHAR(255) NOT NULL, batch INT NOT NULL)');
        $connection->statement('CREATE TABLE users (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, email VARCHAR(254) NOT NULL)');
        $connection->table('platform_control')->insert([
            'id' => 1,
            'super_admin_user_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $connection->table('migrations')->insert([
            ['migration' => '2026_11_19_000001_create_users_table', 'batch' => 1],
            ['migration' => '2026_11_19_000024_create_platform_control_table', 'batch' => 1],
        ]);
        $connection->table('users')->insert([
            'email' => 'existing-user@example.test',
        ]);

        $this->get('https://agency.example.test/install')->assertOk();
        $privateInstallerKey = trim((string) file_get_contents(storage_path('app/private/INSTALLER-ACCESS-KEY.txt')));

        $this->post('https://agency.example.test/install', [
            'installer_token' => $privateInstallerKey,
            'app_name' => 'Talyvane Agency',
            'app_url' => 'https://agency.example.test',
            'db_host' => '127.0.0.1',
            'db_port' => 3306,
            'db_database' => 'talyvane_test',
            'db_username' => 'talyvane',
            'db_password' => 'test-password',
            'mail_scheme' => 'smtp',
            'mail_host' => 'smtp.example.test',
            'mail_port' => 587,
            'mail_username' => 'installer@example.test',
            'mail_password' => 'test-mail-password',
            'mail_from_address' => 'installer@example.test',
            'mail_from_name' => 'Talyvane Agency',
            'admin_name' => 'Deployment Super Admin',
            'admin_email' => 'admin@example.test',
            'recovery_email' => 'recovery@example.test',
        ])->assertSessionHasErrors('installer');

        $this->assertSame(1, $connection->table('users')->count());
        $this->assertSame(2, $connection->table('migrations')->count());
    }

    public function test_browser_install_verifies_emails_creates_lock_shows_password_once_and_enforces_first_login_change(): void
    {
        $this->mock(RecoveryCodeGenerator::class, fn ($mock) => $mock->shouldReceive('generate')->twice()->andReturn('111111', '222222'));
        $installerPage = $this->get('https://agency.example.test/install')->assertOk();
        $privateInstallerKey = trim((string) file_get_contents(storage_path('app/private/INSTALLER-ACCESS-KEY.txt')));
        $this->assertMatchesRegularExpression('/\\A[A-Za-z0-9_-]{43}\\z/D', $privateInstallerKey);
        $this->assertStringNotContainsString($privateInstallerKey, $installerPage->getContent());

        $this->post('https://agency.example.test/install', [
            'installer_token' => $privateInstallerKey,
            'app_name' => 'Talyvane Agency',
            'app_url' => 'https://agency.example.test',
            'db_host' => '127.0.0.1',
            'db_port' => 3306,
            'db_database' => 'talyvane_test',
            'db_username' => 'talyvane',
            'db_password' => 'test-password',
            'mail_scheme' => 'smtp',
            'mail_host' => 'smtp.example.test',
            'mail_port' => 587,
            'mail_username' => 'installer@example.test',
            'mail_password' => 'test-mail-password',
            'mail_from_address' => 'installer@example.test',
            'mail_from_name' => 'Talyvane Agency',
            'admin_name' => 'Deployment Super Admin',
            'admin_email' => 'admin@example.test',
            'recovery_email' => 'recovery@example.test',
        ])->assertRedirect('https://agency.example.test/install/verify');

        Notification::assertSentOnDemandTimes(SuperAdminEmailVerificationCode::class, 2);

        $this->post('https://agency.example.test/install/verify', [
            'primary_code' => '111111',
            'recovery_code' => '222222',
        ])->assertRedirect('https://agency.example.test/install/success');

        $this->assertDirectoryDoesNotExist($this->temporaryRoot.'/package/install');
        $this->assertFileDoesNotExist(storage_path('app/private/INSTALLER-ACCESS-KEY.txt'));

        $admin = User::query()->where('email', 'admin@example.test')->firstOrFail();
        $this->assertNotNull($admin->must_change_password_at);

        $control = DB::table('platform_control')->where('id', 1)->first();
        $this->assertSame($admin->id, $control->super_admin_user_id);
        $this->assertNotNull($control->installation_id);
        $this->assertNotNull($control->installation_completed_at);
        $this->assertTrue((new InstallationLock(base_path(), (string) config('app.key')))->verify(
            $control->installation_id,
            $control->installation_lock_hash
        ));

        $recoveryKey = Crypt::decryptString($control->super_admin_recovery_key_handoff);
        $this->assertNotSame($recoveryKey, $control->super_admin_recovery_key_hash);

        $success = $this->get('https://agency.example.test/install/success')
            ->assertOk()
            ->assertSee('https://agency.example.test/admin/login')
            ->assertSee('The private installer directory was automatically removed.')
            ->assertHeader('Referrer-Policy', 'no-referrer');

        $cacheControl = (string) $success->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);

        $matches = [];
        $this->assertSame(1, preg_match('/<code>([A-Za-z0-9_-]{64})<\/code>/', $success->getContent(), $matches));
        $initialPassword = $matches[1];
        $this->assertTrue(Hash::check($initialPassword, $admin->fresh()->password));
        $this->assertStringNotContainsString($recoveryKey, $success->getContent());

        $this->assertDirectoryDoesNotExist($this->temporaryRoot.'/package/install');
        $this->get('/install/success')->assertNotFound();
        $this->get('https://agency.example.test/install')->assertNotFound();
        $this->get('https://agency.example.test/admin/login')->assertOk();

        $this->post('https://agency.example.test/admin/login', [
            'email' => 'admin@example.test',
            'password' => $initialPassword,
        ])->assertRedirect('https://agency.example.test/admin/first-login/password');

        $this->post('https://agency.example.test/admin/first-login/password', [
            'password' => 'New-Strong-Password-934!',
            'password_confirmation' => 'New-Strong-Password-934!',
        ])->assertRedirect('https://agency.example.test/admin/mfa/setup');

        $this->assertAuthenticatedAs($admin);
        $this->assertNull($admin->fresh()->must_change_password_at);
        $this->assertTrue(Hash::check('New-Strong-Password-934!', $admin->fresh()->password));
    }
    public function test_installer_rejects_a_canonical_url_that_does_not_match_the_request_host(): void
    {
        $page = $this->get('https://agency.example.test/install')->assertOk();
        $privateInstallerKey = trim((string) file_get_contents(storage_path('app/private/INSTALLER-ACCESS-KEY.txt')));
        $this->assertStringNotContainsString($privateInstallerKey, $page->getContent());

        $this->withSession(['installer.started_at' => time()])
            ->post('https://agency.example.test/install', [
                'installer_token' => $privateInstallerKey,
                'app_name' => 'Talyvane Agency',
                'app_url' => 'https://attacker.example.test',
                'db_host' => '127.0.0.1',
                'db_port' => 3306,
                'db_database' => 'talyvane_test',
                'db_username' => 'talyvane',
                'db_password' => 'test-password',
                'mail_scheme' => 'smtp',
                'mail_host' => 'smtp.example.test',
                'mail_port' => 587,
                'mail_username' => 'installer@example.test',
                'mail_password' => 'test-mail-password',
                'mail_from_address' => 'installer@example.test',
                'mail_from_name' => 'Talyvane Agency',
                'admin_name' => 'Deployment Super Admin',
                'admin_email' => 'admin@example.test',
                'recovery_email' => 'recovery@example.test',
            ])
            ->assertStatus(302)
            ->assertSessionHasErrors('app_url');

        $this->assertFalse(DB::connection('mysql')->getSchemaBuilder()->hasTable('users'));
    }

}
