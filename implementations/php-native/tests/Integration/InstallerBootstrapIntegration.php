<?php
declare(strict_types=1);

use Talyvane\Application\Identity\EmailVerificationService;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Infrastructure\Mail\Mailer;
use Talyvane\Infrastructure\Security\RateLimiter;
use Talyvane\Installer\PrivateInstallerState;
use Talyvane\Installer\SetupKey;
use Talyvane\Http\NativeInstallerController;
use Talyvane\Http\Request;
use Talyvane\Security\SecretBox;

function runInstallerBootstrapIntegration(PDO $pdo): int
{
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
        $assert(strlen($setupSecret) === 43 && $setupKey->verify($setupSecret),'installer creates a 256-bit cPanel-only setup key');

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

        $mailer = new class implements Mailer {
            /** @var list<array{recipient:string,subject:string,body:string}> */
            public array $messages = [];
            public function send(string $recipient,string $subject,string $textBody): void
            {
                $this->messages[] = ['recipient'=>$recipient,'subject'=>$subject,'body'=>$textBody];
            }
        };
        $pepper = rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
        $service = new EmailVerificationService($pdo,$mailer,new RateLimiter($pdo,$pepper),new AuditLogger($pdo),$pepper);

        try {
            $service->prepareSuperAdminBootstrap($setupKey,'incorrect-setup-key','first-admin@example.test','recovery-admin@example.test');
            $assert(false,'invalid installer setup key is rejected');
        } catch (DomainException) {
            $assert(true,'invalid installer setup key is rejected');
        }

        $proof = rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
        $bootstrap = $service->prepareSuperAdminBootstrap(
            $setupKey,$setupSecret,'first-admin@example.test','recovery-admin@example.test',$proof
        );
        $assert($bootstrap['bootstrap_proof'] === $proof && count($mailer->messages) === 2,
            'bootstrap creates one pending Super Admin and sends two separate verification messages');

        $codes = [];
        foreach ($mailer->messages as $message) {
            if (!preg_match('/verification code is: ([0-9]{6})/',$message['body'],$match)) {
                throw new RuntimeException('Installer integration failed: verification code was not delivered to the test mailer.');
            }
            $codes[$message['recipient']] = $match[1];
        }
        $userId = $bootstrap['user_id'];
        $primary = $service->verify($userId,'first-admin@example.test','admin_primary',$codes['first-admin@example.test']);
        $recovery = $service->verify($userId,'recovery-admin@example.test','admin_recovery',$codes['recovery-admin@example.test']);
        $assert($primary && $recovery && $service->bothAdminAddressesVerified($userId,'first-admin@example.test','recovery-admin@example.test'),
            'both independent email challenges must be verified before activation');

        $persistedCredentials = null;
        $credentials = $service->activateSuperAdmin(
            $userId,'first-admin@example.test','recovery-admin@example.test',$proof,bin2hex(random_bytes(16)),
            function(array $secrets) use (&$persistedCredentials,$pdo): void {
                if (!$pdo->inTransaction()) {
                    throw new RuntimeException('Installer credentials must be persisted before the activation transaction commits.');
                }
                $persistedCredentials = $secrets;
            }
        );
        $assert(is_array($persistedCredentials) && $persistedCredentials === $credentials,
            'one-time administrator credentials are durably handed to private state before database commit');

        $user = $pdo->prepare('SELECT status,first_login_required,password_hash FROM users WHERE id = :id');
        $user->execute(['id'=>$userId]);
        $userRow = $user->fetch();
        $role = $pdo->prepare('SELECT 1 FROM user_roles WHERE user_id = :id AND role_key = "super_admin"');
        $role->execute(['id'=>$userId]);
        $recoveryRow = $pdo->query('SELECT recovery_email,offline_recovery_key_hash FROM super_admin_recovery WHERE singleton_id=1')->fetch();
        $assert(is_array($userRow) && $userRow['status'] === 'active' && (bool) $userRow['first_login_required'] &&
            password_verify($credentials['initial_password'],$userRow['password_hash']) && $role->fetchColumn() !== false,
            'activation creates an active Super Admin who must change the generated password on first login');
        $assert(is_array($recoveryRow) && $recoveryRow['recovery_email'] === 'recovery-admin@example.test' &&
            password_verify($credentials['offline_recovery_key'],$recoveryRow['offline_recovery_key_hash']),
            'independent recovery email and offline recovery key are stored only as a password hash');
        $activated = $pdo->query('SELECT activated_at FROM super_admin_bootstrap_challenges WHERE singleton_id=1')->fetchColumn();
        $assert($activated !== false && $activated !== null,'successful bootstrap permanently consumes the one-time bootstrap challenge');

        $setupKey->consume();
        $assert(!$setupKey->verify($setupSecret),'private setup key is consumed after successful Super Admin activation');

        $httpHome = $temporaryRoot . DIRECTORY_SEPARATOR . 'http-home';
        $httpDocumentRoot = $httpHome . DIRECTORY_SEPARATOR . 'public_html';
        mkdir($httpDocumentRoot,0755,true);
        $oldServer = $_SERVER;
        $oldHome = getenv('HOME');
        $_SERVER['DOCUMENT_ROOT'] = $httpDocumentRoot;
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['SERVER_PORT'] = '443';
        $_SERVER['HTTP_HOST'] = 'installer.example.test';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/install';
        putenv('HOME=' . $httpHome);
        $installerResponse = (new NativeInstallerController(dirname(__DIR__,2)))->handle(Request::fromGlobals());
        $installerKeyFiles = glob($httpHome . DIRECTORY_SEPARATOR . '.talyvane' . DIRECTORY_SEPARATOR . 'talyvane' . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'INSTALLER-ACCESS-KEY.txt') ?: [];
        $installerKey = count($installerKeyFiles) === 1 ? trim((string) file_get_contents($installerKeyFiles[0])) : '';
        $assert(str_contains($installerResponse->body,'Set up your platform') && in_array($installerResponse->status,[200,503],true),
            'HTTPS browser installer renders its preflight wizard before application bootstrap');
        $keySafe = count($installerKeyFiles) === 1 && strlen($installerKey) === 43 &&
            !str_contains($installerResponse->body,$installerKey) &&
            (fileperms($installerKeyFiles[0]) & 0777) === 0600;
        $assert($keySafe,'browser installer creates a private setup key but never reveals it in HTML');
        if (session_status() === PHP_SESSION_ACTIVE) { session_destroy(); }
        $_SERVER = $oldServer;
        if ($oldHome === false) { putenv('HOME'); } else { putenv('HOME=' . $oldHome); }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($httpHome,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            if ($file->isDir() && !$file->isLink()) { rmdir($file->getPathname()); }
            else { unlink($file->getPathname()); }
        }
        rmdir($httpHome);
    } finally {
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
