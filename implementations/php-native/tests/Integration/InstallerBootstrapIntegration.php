<?php
declare(strict_types=1);

use Talyvane\Http\NativeInstallerController;
use Talyvane\Http\Request;
use Talyvane\Infrastructure\Database\ConnectionFactory;
use Talyvane\Infrastructure\Mail\Mailer;
use Talyvane\Installer\PrivateInstallerState;
use Talyvane\Installer\SetupKey;
use Talyvane\Security\Csrf;
use Talyvane\Security\SecretBox;

function runInstallerBootstrapIntegration(PDO $pdo): int
{
    $sharedDatabase = $pdo;
    $databaseName = 'talyvane_installer_' . bin2hex(random_bytes(5));
    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = (int) (getenv('DB_PORT') ?: 3306);
    $username = getenv('DB_USER') ?: 'root';
    $password = getenv('DB_PASSWORD') ?: '';
    $server = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4',$host,$port),$username,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    if (!preg_match('/^[a-zA-Z0-9_]{1,64}$/D',$databaseName)) { throw new RuntimeException('Installer integration database name is invalid.'); }
    $server->exec('CREATE DATABASE `' . $databaseName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo = ConnectionFactory::mysql(['host'=>$host,'port'=>$port,'database'=>$databaseName,'username'=>$username,'password'=>$password]);
    $assertions = 0;
    $assert = static function(bool $condition,string $message) use (&$assertions): void {
        $assertions++;
        if (!$condition) {
            throw new RuntimeException('Installer integration failed: ' . $message);
        }
    };

    $temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'talyvane-installer-' . bin2hex(random_bytes(8));
    $privateConfig = $temporaryRoot . DIRECTORY_SEPARATOR . '.talyvane' . DIRECTORY_SEPARATOR . 'config';
    $publicRoot = $temporaryRoot . DIRECTORY_SEPARATOR . 'public_html';
    mkdir($privateConfig,0700,true);
    mkdir($publicRoot,0755,true);

    try {
        $setupKey = new SetupKey($privateConfig . DIRECTORY_SEPARATOR . 'INSTALLER-ACCESS-KEY.txt');
        $setupSecret = $setupKey->create();
        $assert(strlen($setupSecret) === 43 && $setupKey->verify($setupSecret) && $setupKey->read() === $setupSecret,
            'installer creates a 256-bit cPanel-only setup key');

        $pendingStore = new PrivateInstallerState(
            $privateConfig . DIRECTORY_SEPARATOR . 'pending.json',$publicRoot,
            new SecretBox($setupSecret),'native-installer-pending-v1'
        );
        $pendingStore->write(['database_password'=>'do-not-store-in-plaintext','proof'=>'proof-value']);
        $pendingRaw = (string) file_get_contents($privateConfig . DIRECTORY_SEPARATOR . 'pending.json');
        $pendingMode = fileperms($privateConfig . DIRECTORY_SEPARATOR . 'pending.json') & 0777;
        $pendingRead = $pendingStore->read();
        $assert(!str_contains($pendingRaw,'do-not-store-in-plaintext') && $pendingMode === 0600,
            'pending installer credentials are encrypted and owner-only');
        $assert(is_array($pendingRead) && $pendingRead['database_password'] === 'do-not-store-in-plaintext',
            'encrypted installer state round-trips without exposing plaintext');
        $pendingStore->delete();
        $setupKey->consume();

        $httpHome = $temporaryRoot . DIRECTORY_SEPARATOR . 'http-home';
        $httpDocumentRoot = $httpHome . DIRECTORY_SEPARATOR . 'public_html';
        mkdir($httpDocumentRoot,0755,true);
        $applicationRoot = $httpHome . DIRECTORY_SEPARATOR . '.talyvane' . DIRECTORY_SEPARATOR . 'apps' . DIRECTORY_SEPARATOR . 'installer' . DIRECTORY_SEPARATOR . 'talyvane-native-app';
        mkdir($applicationRoot,0700,true);
        $sourceRoot = dirname(__DIR__,2);
        foreach (['src','database','views','public'] as $directory) {
            $sourceDirectory = $sourceRoot . DIRECTORY_SEPARATOR . $directory;
            $targetDirectory = $applicationRoot . DIRECTORY_SEPARATOR . $directory;
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceDirectory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
            foreach ($iterator as $entry) {
                $target = $targetDirectory . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
                if ($entry->isDir()) { if (!is_dir($target)) { mkdir($target,0700,true); } }
                elseif ($entry->isFile() && !$entry->isLink()) { if (!is_dir(dirname($target))) { mkdir(dirname($target),0700,true); } copy($entry->getPathname(),$target); }
            }
        }
        copy($sourceRoot . DIRECTORY_SEPARATOR . 'bootstrap.php',$applicationRoot . DIRECTORY_SEPARATOR . 'bootstrap.php');
        $oldServer = $_SERVER;
        $oldGet = $_GET;
        $oldPost = $_POST;
        $oldCookie = $_COOKIE;
        $oldRequest = $_REQUEST;
        $oldSession = $_SESSION ?? [];
        $oldHome = getenv('HOME');
        $_SERVER['DOCUMENT_ROOT'] = $httpDocumentRoot;
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['SERVER_PORT'] = '443';
        $_SERVER['HTTP_HOST'] = 'installer.example.test';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/install';
        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        $_REQUEST = [];
        putenv('HOME=' . $httpHome);

        $mailer = new class implements Mailer {
            /** @var list<array{recipient:string,subject:string,body:string}> */
            public array $messages = [];
            public function send(string $recipient,string $subject,string $textBody): void
            {
                $this->messages[] = ['recipient'=>$recipient,'subject'=>$subject,'body'=>$textBody];
            }
        };
        $controller = new NativeInstallerController(
            $applicationRoot,
            static fn(array $config): PDO => $pdo,
            static fn(array $config): Mailer => $mailer
        );
        $getResponse = $controller->handle(new Request('GET','/install',['host'=>'installer.example.test']));
        $keyFiles = glob($httpHome . DIRECTORY_SEPARATOR . '.talyvane' . DIRECTORY_SEPARATOR . 'talyvane' . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'INSTALLER-ACCESS-KEY.txt') ?: [];
        $installerKey = count($keyFiles) === 1 ? trim((string) file_get_contents($keyFiles[0])) : '';
        $assert(str_contains($getResponse->body,'Set up your platform') && in_array($getResponse->status,[200,503],true),
            'HTTPS browser installer renders its environment preflight before application bootstrap');
        $assert(count($keyFiles) === 1 && strlen($installerKey) === 43 && !str_contains($getResponse->body,$installerKey) &&
            (fileperms($keyFiles[0]) & 0777) === 0600,
            'browser installer creates a private setup key but never reveals it in HTML');

        $invalidCsrf = Csrf::issue();
        $invalidRequest = new Request('POST','/install',['host'=>'installer.example.test'],[],[
            '_csrf'=>$invalidCsrf,'setup_key'=>'invalid','installation_confirmed'=>'1',
        ]);
        $invalidResponse = $controller->handle($invalidRequest);
        $assert($invalidResponse->status === 403 && $mailer->messages === [],
            'invalid setup key cannot start database or email bootstrap');

        $csrf = Csrf::issue();
        $setupRequest = new Request('POST','/install',['host'=>'installer.example.test'],[],[
            '_csrf'=>$csrf,'setup_key'=>$installerKey,'installation_confirmed'=>'1',
            'app_name'=>'Talyvane Test','app_url'=>'https://installer.example.test',
            'db_host'=>'localhost','db_port'=>'3306','db_name'=>'talyvane_native_test',
            'db_user'=>'native_test','db_password'=>'  database-secret  ',
            'mail_host'=>'smtp.example.test','mail_port'=>'587','mail_username'=>'mailer@example.test',
            'mail_password'=>'  smtp-secret  ','mail_encryption'=>'tls',
            'mail_from_email'=>'no-reply@example.test','mail_from_name'=>'Talyvane Test',
            'admin_email'=>'first-admin@example.test','recovery_email'=>'recovery-admin@example.test',
        ],'127.0.0.1',bin2hex(random_bytes(16)));
        $setupResponse = $controller->handle($setupRequest);
        $assert($setupResponse->status === 303 && ($setupResponse->headers['location'] ?? '') === '/install/verify' &&
            count($mailer->messages) === 2,
            'native installer migrates the database and sends separate primary/recovery verification codes');

        $verifyPage = $controller->handle(new Request('GET','/install/verify',['host'=>'installer.example.test']));
        $assert($verifyPage->status === 200 && str_contains($verifyPage->body,'Verify both administrator addresses'),
            'installer exposes a separate dual-email verification step');
        $codes = [];
        foreach ($mailer->messages as $message) {
            if (!preg_match('/verification code is: ([0-9]{6})/',$message['body'],$match)) {
                throw new RuntimeException('Installer integration failed: verification code was not delivered to the test mailer.');
            }
            $codes[$message['recipient']] = $match[1];
        }
        $verifyCsrf = Csrf::issue();
        $verifyRequest = new Request('POST','/install/verify',['host'=>'installer.example.test'],[],[
            '_csrf'=>$verifyCsrf,
            'primary_code'=>$codes['first-admin@example.test'],
            'recovery_code'=>$codes['recovery-admin@example.test'],
        ],'127.0.0.1',bin2hex(random_bytes(16)));
        $verifyResponse = $controller->handle($verifyRequest);
        $assert($verifyResponse->status === 303 && ($verifyResponse->headers['location'] ?? '') === '/install/success',
            'both independent email codes activate the first Super Admin and create the permanent lock');

        $successResponse = $controller->handle(new Request('GET','/install/success',['host'=>'installer.example.test']));
        $assert($successResponse->status === 200 && str_contains($successResponse->body,'One-time administrator credentials'),
            'installer shows the one-time administrator credential handoff after lock creation');
        preg_match('/<label>Initial Super Admin password<\/label><code>([^<]+)<\/code>/',$successResponse->body,$passwordMatch);
        $initialPassword = html_entity_decode($passwordMatch[1] ?? '',ENT_QUOTES | ENT_HTML5,'UTF-8');
        $assert($initialPassword !== '' && !str_contains($successResponse->body,'Offline Super Admin recovery key') &&
            str_contains($successResponse->body,'cPanel Terminal'),
            'browser handoff displays only the initial password and directs recovery-key retrieval to the CLI');

        $configFiles = glob($httpHome . DIRECTORY_SEPARATOR . '.talyvane' . DIRECTORY_SEPARATOR . 'talyvane' . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php') ?: [];
        $lockFiles = glob($httpHome . DIRECTORY_SEPARATOR . '.talyvane' . DIRECTORY_SEPARATOR . 'talyvane' . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'INSTALLATION-LOCK.json') ?: [];
        $keyStillExists = glob($httpHome . DIRECTORY_SEPARATOR . '.talyvane' . DIRECTORY_SEPARATOR . 'talyvane' . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'INSTALLER-ACCESS-KEY.txt') ?: [];
        $assert(count($configFiles) === 1 && count($lockFiles) === 1 && $keyStillExists === [] &&
            (fileperms($configFiles[0]) & 0777) === 0600,
            'installer writes private configuration and permanent lock, then consumes the setup key');
        $installedConfig = count($configFiles) === 1 ? require $configFiles[0] : [];
        $assert(is_array($installedConfig) && $installedConfig['database']['password'] === '  database-secret  ' &&
            $installedConfig['mail']['password'] === '  smtp-secret  ' &&
            !str_starts_with(realpath($configFiles[0]) ?: '',realpath($httpDocumentRoot) ?: '/invalid-public-root') &&
            ($installedConfig['paths']['application'] ?? null) === realpath($applicationRoot),
            'installer preserves exact connection secrets and records the actual private application code root');
        $recoveryHandoffFiles = glob(dirname($configFiles[0]) . DIRECTORY_SEPARATOR . 'INSTALLER-RECOVERY-HANDOFF.json') ?: [];
        $cliScript = $applicationRoot . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'reveal-super-admin-recovery-key.php';
        $runRecoveryCli = static function() use ($cliScript,$applicationRoot,$httpHome,$configFiles): array {
            $process = proc_open([PHP_BINARY,$cliScript],[
                0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w'],
            ],$pipes,$applicationRoot,[
                'HOME'=>$httpHome,'TALYVANE_CONFIG'=>$configFiles[0],
            ]);
            if (!is_resource($process)) { throw new RuntimeException('Could not start the recovery-key CLI integration process.'); }
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            return ['status'=>proc_close($process),'stdout'=>is_string($stdout)?$stdout:'','stderr'=>is_string($stderr)?$stderr:''];
        };
        $cliResult = count($recoveryHandoffFiles) === 1 ? $runRecoveryCli() : ['status'=>1,'stdout'=>'','stderr'=>''];
        $offlineRecoveryKey = trim($cliResult['stdout']);
        $assert($cliResult['status'] === 0 && strlen($offlineRecoveryKey) === 43 &&
            !str_contains($successResponse->body,$offlineRecoveryKey) && !file_exists($recoveryHandoffFiles[0] ?? ''),
            'offline recovery key is encrypted outside the browser and consumed once through the private CLI');
        $secondCliResult = $runRecoveryCli();
        $assert($secondCliResult['status'] === 1 && trim($secondCliResult['stdout']) === '',
            'offline recovery key cannot be retrieved twice through the CLI');

        $installedUser = $pdo->prepare('SELECT status,first_login_required,password_hash FROM users WHERE email = "first-admin@example.test" LIMIT 1');
        $installedUser->execute();
        $user = $installedUser->fetch();
        $role = $pdo->prepare('SELECT 1 FROM user_roles WHERE user_id = (SELECT id FROM users WHERE email = "first-admin@example.test") AND role_key = "super_admin"');
        $role->execute();
        $recovery = $pdo->query('SELECT recovery_email,offline_recovery_key_hash FROM super_admin_recovery WHERE singleton_id=1')->fetch();
        $assert(is_array($user) && $user['status'] === 'active' && (bool) $user['first_login_required'] &&
            password_verify($initialPassword,$user['password_hash']) && $role->fetchColumn() !== false,
            'installed Super Admin must change the generated password and receives the Super Admin role');
        $assert(is_array($recovery) && $recovery['recovery_email'] === 'recovery-admin@example.test' &&
            password_verify($offlineRecoveryKey,$recovery['offline_recovery_key_hash']),
            'recovery email and offline recovery key are stored as a password hash');

        $secondSuccess = $controller->handle(new Request('GET','/install/success',['host'=>'installer.example.test']));
        $assert($secondSuccess->status === 410,'one-time installer credentials cannot be retrieved a second time');

        if (session_status() === PHP_SESSION_ACTIVE) { session_destroy(); }
        $_SERVER = $oldServer;
        $_GET = $oldGet;
        $_POST = $oldPost;
        $_COOKIE = $oldCookie;
        $_REQUEST = $oldRequest;
        $_SESSION = $oldSession;
        if ($oldHome === false) { putenv('HOME'); } else { putenv('HOME=' . $oldHome); }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($httpHome,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            if ($file->isDir() && !$file->isLink()) { rmdir($file->getPathname()); }
            else { unlink($file->getPathname()); }
        }
        rmdir($httpHome);
    } finally {
        $pdo = null;
        $server->exec('DROP DATABASE IF EXISTS `' . $databaseName . '`');
        $sharedBootstrapCount = (int) $sharedDatabase->query('SELECT COUNT(*) FROM super_admin_bootstrap_challenges')->fetchColumn();
        if ($sharedBootstrapCount !== 0) { throw new RuntimeException('Installer integration leaked bootstrap state into the shared identity database.'); }
        if (is_dir($privateConfig)) {
            foreach (glob($privateConfig . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                if (is_file($file) && !is_link($file)) { @unlink($file); }
            }
        }
        @rmdir($privateConfig);
        @rmdir(dirname($privateConfig));
        @rmdir($publicRoot);
        @rmdir($temporaryRoot);
    }
    return $assertions;
}
