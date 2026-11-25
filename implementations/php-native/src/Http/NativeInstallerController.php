<?php
declare(strict_types=1);

namespace Talyvane\Http;

use DomainException;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;
use Talyvane\Application\Identity\EmailVerificationService;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Infrastructure\Database\ConnectionFactory;
use Talyvane\Infrastructure\Database\MigrationRunner;
use Talyvane\Infrastructure\Database\ReferenceDataImporter;
use Talyvane\Infrastructure\Mail\SmtpMailer;
use Talyvane\Infrastructure\Security\RateLimiter;
use Talyvane\Installer\EnvironmentPreflight;
use Talyvane\Installer\InstallerLock;
use Talyvane\Installer\PrivateConfigStore;
use Talyvane\Installer\PrivateInstallerState;
use Talyvane\Installer\PrivatePathResolver;
use Talyvane\Installer\SetupKey;
use Talyvane\Infrastructure\Logging\PrivateErrorLogger;
use Talyvane\Security\Csrf;
use Talyvane\Security\SecretBox;
use Talyvane\Security\SessionManager;

final class NativeInstallerController
{
    private ?array $paths = null;

    public function __construct(private string $applicationRoot) {}

    public function handle(Request $request): Response
    {
        try {
            if (!$this->isHttps()) {
                return $this->plainError('HTTPS is required to run the installer.',400);
            }
            SessionManager::start();
            $paths = $this->paths();
            $setupKey = new SetupKey($paths['config'] . '/INSTALLER-ACCESS-KEY.txt');
            $lock = new InstallerLock($paths['config'] . '/INSTALLATION-LOCK.json');
            $locked = $lock->isLocked();

            if ($request->path === '/install/success' && $request->method === 'GET') {
                return $this->successPage($paths,$lock);
            }
            if ($locked) {
                return Response::redirect('/admin/login');
            }
            if ($request->path === '/install' && $request->method === 'GET') {
                return $this->setupPage($request,$paths,$setupKey);
            }
            if ($request->path === '/install' && $request->method === 'POST') {
                return $this->submit($request,$paths,$setupKey,$lock);
            }
            if ($request->path === '/install/verify' && $request->method === 'GET') {
                return $this->verifyPage($request,$paths,$setupKey);
            }
            if ($request->path === '/install/verify' && $request->method === 'POST') {
                return $this->verify($request,$paths,$setupKey,$lock);
            }
            if ($request->path === '/install/resend' && $request->method === 'POST') {
                return $this->resend($request,$paths,$setupKey);
            }
            return $this->plainError('Installer route not found.',404);
        } catch (Throwable $error) {
            $this->logFailure($error);
            return $this->plainError('The installer could not complete this request. Check the private application error log and retry only after reviewing the installation state.',503);
        }
    }

    private function setupPage(Request $request,array $paths,SetupKey $setupKey,string $error='',int $status=200): Response
    {
        $configFile = $paths['config'] . '/app.php';
        if (file_exists($configFile)) {
            return $this->render('install/wizard',[
                'step'=>'setup','error'=>'A private application configuration already exists without a verified installation lock. Do not overwrite it; inspect the private installation state before proceeding.',
                'notice'=>'','checks'=>[],'keyFileHint'=>'INSTALLER-ACCESS-KEY.txt',
            ],503);
        }
        $keyFile = $paths['config'] . '/INSTALLER-ACCESS-KEY.txt';
        if (!is_file($keyFile)) {
            $setupKey->create();
        }
        $pending = $this->pendingState($paths,$setupKey)->read();
        if (is_array($pending)) {
            return Response::redirect('/install/verify');
        }
        $preflight = $this->preflight($paths);
        return $this->render('install/wizard',[
            'step'=>'setup','error'=>$error,'notice'=>'',
            'checks'=>$preflight['checks'],
            'keyFileHint'=>$this->relativePrivatePath($paths,$keyFile),
        ],$status === 200 ? ($preflight['ok'] ? 200 : 503) : $status);
    }

    private function submit(Request $request,array $paths,SetupKey $setupKey,InstallerLock $lock): Response
    {
        if (!Csrf::verify($this->field($request,'_csrf'))) {
            return $this->setupPage($request,$paths,$setupKey,'Your session expired. Refresh the installer and try again.',419);
        }
        $this->consumeSessionAttempt();
        if (!$setupKey->verify($this->field($request,'setup_key'))) {
            return $this->setupPage($request,$paths,$setupKey,'The private setup key is invalid.',403);
        }
        if ($lock->isLocked() || is_file($paths['config'] . '/app.php')) {
            return $this->plainError('Installation is already configured or locked.',409);
        }
        $preflight = $this->preflight($paths);
        if (!$preflight['ok']) {
            return $this->render('install/wizard',[
                'step'=>'setup','error'=>'The server does not meet the required installation checks.',
                'notice'=>'','checks'=>$preflight['checks'],
                'keyFileHint'=>$this->relativePrivatePath($paths,$paths['config'] . '/INSTALLER-ACCESS-KEY.txt'),
            ],503);
        }
        if ($this->field($request,'installation_confirmed') !== '1') {
            return $this->setupPage($request,$paths,$setupKey,'Confirm the dedicated database and authorized SMTP sender before continuing.',422);
        }

        try {
            $appName = $this->requiredText($request,'app_name',2,120);
            $appUrl = $this->canonicalHttpsUrl($this->requiredText($request,'app_url',12,255),$request);
            $database = [
                'host'=>$this->requiredText($request,'db_host',1,255),
                'port'=>$this->integerField($request,'db_port',1,65535),
                'database'=>$this->requiredText($request,'db_name',1,64),
                'username'=>$this->requiredText($request,'db_user',1,128),
                'password'=>$this->requiredText($request,'db_password',1,1024),
            ];
            $mail = [
                'host'=>$this->requiredText($request,'mail_host',1,253),
                'port'=>$this->integerField($request,'mail_port',1,65535),
                'username'=>$this->requiredText($request,'mail_username',1,254),
                'password'=>$this->requiredText($request,'mail_password',1,1024),
                'from_email'=>$this->requiredText($request,'mail_from_email',3,254),
                'from_name'=>$this->requiredText($request,'mail_from_name',1,120),
                'encryption'=>$this->requiredText($request,'mail_encryption',3,3),
                'timeout'=>10,
            ];
            $primaryEmail = strtolower($this->requiredText($request,'admin_email',3,254));
            $recoveryEmail = strtolower($this->requiredText($request,'recovery_email',3,254));
            if (!filter_var($primaryEmail,FILTER_VALIDATE_EMAIL) || !filter_var($recoveryEmail,FILTER_VALIDATE_EMAIL) ||
                hash_equals($primaryEmail,$recoveryEmail)) {
                throw new InvalidArgumentException('Use two distinct valid administrator email addresses.');
            }
            if (!filter_var($mail['from_email'],FILTER_VALIDATE_EMAIL) ||
                !in_array($mail['encryption'],['tls','ssl'],true) ||
                preg_match('/[\r\n]/',$mail['from_name'])) {
                throw new InvalidArgumentException('SMTP sender details or encryption mode are invalid.');
            }

            $pdo = ConnectionFactory::mysql($database);
            $this->assertInstallableDatabase($pdo);
            (new MigrationRunner($pdo,$this->applicationRoot . '/database/migrations'))->migrate();
            $migrationCount = (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
            $migrationFiles = glob($this->applicationRoot . '/database/migrations/*.php') ?: [];
            if ($migrationCount !== count($migrationFiles)) {
                throw new RuntimeException('The native database schema did not reach the expected migration version.');
            }
            $referenceData = new ReferenceDataImporter($pdo);
            $referenceData->importIfNeeded(
                $this->applicationRoot . '/database/reference-data/countries.json',
                $this->applicationRoot . '/database/reference-data/currencies.json'
            );

            $appKey = rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
            $bootstrapProof = rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
            $pending = [
                'phase'=>'preparing','app_name'=>$appName,'app_url'=>$appUrl,
                'database'=>$database,'mail'=>$mail,'app_key'=>$appKey,
                'primary_email'=>$primaryEmail,'recovery_email'=>$recoveryEmail,
                'bootstrap_proof'=>$bootstrapProof,'created_at'=>time(),'expires_at'=>time()+1800,
            ];
            $nonce = bin2hex(random_bytes(32));
            $pending['session_nonce_hash'] = hash('sha256',$nonce);
            $pendingStore = $this->pendingState($paths,$setupKey);
            $pendingStore->write($pending);

            try {
                $mailer = new SmtpMailer($mail);
                $emailVerification = new EmailVerificationService(
                    $pdo,$mailer,new RateLimiter($pdo,$appKey),new AuditLogger($pdo),$appKey
                );
                $bootstrap = $emailVerification->prepareSuperAdminBootstrap(
                    $setupKey,$this->field($request,'setup_key'),$primaryEmail,$recoveryEmail,$bootstrapProof
                );
                $pending['phase'] = 'pending';
                $pending['user_id'] = $bootstrap['user_id'];
                $pending['expires_at'] = time()+1800;
                $pendingStore->write($pending);
            } catch (Throwable $error) {
                $pendingStore->delete();
                throw $error;
            }

            $_SESSION['installer_pending_nonce'] = $nonce;
            return Response::redirect('/install/verify');
        } catch (InvalidArgumentException|DomainException $error) {
            return $this->setupPage($request,$paths,$setupKey,$error->getMessage(),422);
        } catch (Throwable $error) {
            $this->logFailure($error);
            return $this->setupPage($request,$paths,$setupKey,'Database or SMTP setup failed. Verify the supplied connection details and retry.',503);
        }
    }

    private function verifyPage(Request $request,array $paths,SetupKey $setupKey,string $error='',string $notice='',int $status=200): Response
    {
        $pending = $this->pendingState($paths,$setupKey)->read();
        if (!is_array($pending)) {
            return Response::redirect('/install');
        }
        if ((int) ($pending['expires_at'] ?? 0) <= time()) {
            $this->pendingState($paths,$setupKey)->delete();
            return $this->setupPage($request,$paths,$setupKey,'The bootstrap challenge expired. Start the installer again.');
        }
        $nonce = $_SESSION['installer_pending_nonce'] ?? null;
        if (!is_string($nonce) || !hash_equals((string) ($pending['session_nonce_hash'] ?? ''),hash('sha256',$nonce))) {
            $nonce = bin2hex(random_bytes(32));
            $pending['session_nonce_hash'] = hash('sha256',$nonce);
            $this->pendingState($paths,$setupKey)->write($pending);
            $_SESSION['installer_pending_nonce'] = $nonce;
        }
        return $this->render('install/wizard',[
            'step'=>'verify','error'=>$error,'notice'=>$notice,
            'primaryEmail'=>$this->maskEmail((string) ($pending['primary_email'] ?? '')),
            'recoveryEmail'=>$this->maskEmail((string) ($pending['recovery_email'] ?? '')),
            'expiresAt'=>gmdate('Y-m-d H:i:s',(int) ($pending['expires_at'] ?? time())),
        ],$status);
    }

    private function verify(Request $request,array $paths,SetupKey $setupKey,InstallerLock $lock): Response
    {
        if (!Csrf::verify($this->field($request,'_csrf'))) {
            return $this->verifyPage($request,$paths,$setupKey,'Your session expired. Refresh this page and try again.');
        }
        $pendingStore = $this->pendingState($paths,$setupKey);
        $pending = $pendingStore->read();
        $nonce = $_SESSION['installer_pending_nonce'] ?? null;
        if (!is_array($pending) || !is_string($nonce) ||
            !hash_equals((string) ($pending['session_nonce_hash'] ?? ''),hash('sha256',$nonce)) ||
            (int) ($pending['expires_at'] ?? 0) <= time()) {
            return $this->plainError('The installer verification session is invalid or expired.',419);
        }

        try {
            $pdo = ConnectionFactory::mysql($pending['database']);
            $emailVerification = new EmailVerificationService(
                $pdo,new SmtpMailer($pending['mail']),new RateLimiter($pdo,$pending['app_key']),
                new AuditLogger($pdo),(string) $pending['app_key']
            );
            $bootstrapQuery = $pdo->prepare(
                'SELECT user_id,activated_at FROM super_admin_bootstrap_challenges
                 WHERE singleton_id=1 AND primary_email=:primary AND recovery_email=:recovery
                   AND proof_hash=:proof LIMIT 1'
            );
            $bootstrapQuery->execute([
                'primary'=>$pending['primary_email'],'recovery'=>$pending['recovery_email'],
                'proof'=>hash_hmac('sha256',(string) $pending['bootstrap_proof'],(string) $pending['app_key']),
            ]);
            $bootstrapRow = $bootstrapQuery->fetch();
            if (!is_array($bootstrapRow)) {
                $bootstrap = $emailVerification->prepareSuperAdminBootstrap(
                    $setupKey,$setupKey->read(),(string) $pending['primary_email'],
                    (string) $pending['recovery_email'],(string) $pending['bootstrap_proof']
                );
                $pending['user_id'] = $bootstrap['user_id'];
                $pending['phase'] = 'pending';
                $pendingStore->write($pending);
                $bootstrapRow = ['user_id'=>$bootstrap['user_id'],'activated_at'=>null];
            } else {
                $pending['user_id'] = (string) $bootstrapRow['user_id'];
            }

            if ($bootstrapRow['activated_at'] !== null) {
                if (!is_string($pending['initial_password'] ?? null) || !is_string($pending['offline_recovery_key'] ?? null)) {
                    throw new RuntimeException('Activated Super Admin credentials are unavailable in the private pending state.');
                }
                $pending['phase'] = 'activated';
                $pendingStore->write($pending);
            } elseif (($pending['phase'] ?? '') !== 'activated') {
                $primaryCode = $this->field($request,'primary_code');
                $recoveryCode = $this->field($request,'recovery_code');
                if (preg_match('/^[0-9]{6}$/D',$primaryCode)) {
                    $emailVerification->verify((string) $pending['user_id'],(string) $pending['primary_email'],'admin_primary',$primaryCode);
                }
                if (preg_match('/^[0-9]{6}$/D',$recoveryCode)) {
                    $emailVerification->verify((string) $pending['user_id'],(string) $pending['recovery_email'],'admin_recovery',$recoveryCode);
                }
                if (!$emailVerification->bothAdminAddressesVerified(
                    (string) $pending['user_id'],(string) $pending['primary_email'],(string) $pending['recovery_email']
                )) {
                    return $this->verifyPage($request,$paths,$setupKey,'Both verification codes must be correct and current.');
                }
                $credentials = $emailVerification->activateSuperAdmin(
                    (string) $pending['user_id'],(string) $pending['primary_email'],
                    (string) $pending['recovery_email'],(string) $pending['bootstrap_proof'],$request->requestId,
                    function(array $credentials) use (&$pending,$pendingStore): void {
                        $pending['phase'] = 'activating';
                        $pending['initial_password'] = $credentials['initial_password'];
                        $pending['offline_recovery_key'] = $credentials['offline_recovery_key'];
                        $pendingStore->write($pending);
                    }
                );
                $pending['phase'] = 'activated';
                $pending['initial_password'] = $credentials['initial_password'];
                $pending['offline_recovery_key'] = $credentials['offline_recovery_key'];
                $pendingStore->write($pending);
            }

            return $this->finalize($paths,$setupKey,$lock,$pendingStore,$pending);
        } catch (DomainException|InvalidArgumentException $error) {
            return $this->verifyPage($request,$paths,$setupKey,$error->getMessage(),'',422);
        } catch (Throwable $error) {
            $this->logFailure($error);
            return $this->verifyPage($request,$paths,$setupKey,'Verification or final installation failed. Your private installer state has been preserved for a safe retry.',503);
        }
    }

    private function finalize(array $paths,SetupKey $setupKey,InstallerLock $lock,PrivateInstallerState $pendingStore,array $pending): Response
    {
        $configFile = $paths['config'] . '/app.php';
        $config = [
            'app_key'=>(string) $pending['app_key'],
            'app_name'=>(string) $pending['app_name'],
            'app_url'=>(string) $pending['app_url'],
            'database'=>$pending['database'],
            'mail'=>$pending['mail'],
            'paths'=>[
                'application'=>$paths['application'],'config'=>$paths['config'],
                'logs'=>$paths['logs'],'storage'=>$paths['storage'],'backups'=>$paths['backups'],
            ],
            'installed_at'=>gmdate('Y-m-d H:i:s'),
        ];
        $configStore = new PrivateConfigStore($configFile,$paths['document_root']);
        if (!is_file($configFile)) {
            $configStore->create($config);
        } else {
            $existing = $configStore->load();
            if (!hash_equals((string) ($existing['app_key'] ?? ''),(string) $pending['app_key'])) {
                throw new RuntimeException('Existing private configuration does not match the pending installation.');
            }
        }
        if (!$lock->isLocked()) {
            $installationId = self::uuid();
            $lock->lock($installationId);
        }
        $successToken = bin2hex(random_bytes(32));
        $successStore = new PrivateInstallerState(
            $paths['config'] . '/INSTALLER-SUCCESS.json',$paths['document_root'],
            new SecretBox((string) $pending['app_key']),'native-installer-success-v1'
        );
        $successStore->write([
            'token_hash'=>hash('sha256',$successToken),
            'expires_at'=>time()+900,
            'initial_password'=>(string) $pending['initial_password'],
            'offline_recovery_key'=>(string) $pending['offline_recovery_key'],
            'app_name'=>(string) $pending['app_name'],
            'app_url'=>(string) $pending['app_url'],
        ]);
        $pendingStore->delete();
        $setupKey->consume();
        unset($_SESSION['installer_pending_nonce']);
        $_SESSION['installer_success_token'] = $successToken;
        return Response::redirect('/install/success');
    }

    private function resend(Request $request,array $paths,SetupKey $setupKey): Response
    {
        if (!Csrf::verify($this->field($request,'_csrf'))) {
            return $this->verifyPage($request,$paths,$setupKey,'Your session expired. Refresh this page and try again.');
        }
        if (!$setupKey->verify($this->field($request,'setup_key'))) {
            return $this->verifyPage($request,$paths,$setupKey,'The private setup key is invalid.');
        }
        $pending = $this->pendingState($paths,$setupKey)->read();
        $nonce = $_SESSION['installer_pending_nonce'] ?? null;
        if (!is_array($pending) || !is_string($nonce) ||
            !hash_equals((string) ($pending['session_nonce_hash'] ?? ''),hash('sha256',$nonce))) {
            return $this->plainError('The installer verification session is invalid.',419);
        }
        try {
            $pdo = ConnectionFactory::mysql($pending['database']);
            $service = new EmailVerificationService(
                $pdo,new SmtpMailer($pending['mail']),new RateLimiter($pdo,$pending['app_key']),
                new AuditLogger($pdo),(string) $pending['app_key']
            );
            $service->resendSuperAdminVerification($setupKey,$this->field($request,'setup_key'),(string) $pending['bootstrap_proof']);
            return $this->verifyPage($request,$paths,$setupKey,'','New verification codes have been sent to both addresses.');
        } catch (Throwable $error) {
            $this->logFailure($error);
            return $this->verifyPage($request,$paths,$setupKey,'Verification codes could not be resent. Check the SMTP service and try again.',503);
        }
    }

    private function successPage(array $paths,InstallerLock $lock): Response
    {
        if (!$lock->isLocked()) {
            return $this->plainError('Installation success state is unavailable.',404);
        }
        $config = (new PrivateConfigStore($paths['config'] . '/app.php',$paths['document_root']))->load();
        $store = new PrivateInstallerState(
            $paths['config'] . '/INSTALLER-SUCCESS.json',$paths['document_root'],
            new SecretBox((string) $config['app_key']),'native-installer-success-v1'
        );
        $success = $store->read();
        $token = $_SESSION['installer_success_token'] ?? null;
        if (!is_array($success) || !is_string($token) ||
            !hash_equals((string) ($success['token_hash'] ?? ''),hash('sha256',$token)) ||
            (int) ($success['expires_at'] ?? 0) <= time()) {
            if (is_array($success) && (int) ($success['expires_at'] ?? 0) <= time()) {
                $store->delete();
            }
            return $this->plainError('One-time installation credentials are unavailable or have already been viewed.',410);
        }
        $store->delete();
        unset($_SESSION['installer_success_token']);
        return $this->render('install/success',[
            'brandName'=>(string) ($success['app_name'] ?? 'Talyvane'),
            'initialPassword'=>(string) ($success['initial_password'] ?? ''),
            'offlineRecoveryKey'=>(string) ($success['offline_recovery_key'] ?? ''),
            'appUrl'=>(string) ($success['app_url'] ?? ''),
        ]);
    }

    private function preflight(array $paths): array
    {
        $result = EnvironmentPreflight::inspect($paths['home']);
        $root = realpath($this->applicationRoot);
        $privateCode = $root !== false && !$this->isWithin($root,$paths['document_root']);
        $https = $this->isHttps();
        $result['checks']['private_code_root'] = [
            'ok'=>$privateCode,
            'detail'=>$privateCode ? 'Application code is outside the public document root.' : 'Application code must be outside the public document root.',
        ];
        $result['checks']['https'] = [
            'ok'=>$https,
            'detail'=>$https ? 'HTTPS is active.' : 'HTTPS must be enabled before installation.',
        ];
        $result['ok'] = $result['ok'] && $privateCode && $https;
        return $result;
    }

    private function paths(): array
    {
        if ($this->paths !== null) {
            return $this->paths;
        }
        $documentRoot = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
        $accountHome = (string) (getenv('HOME') ?: ($_SERVER['HOME'] ?? ''));
        if ($accountHome === '' && $documentRoot !== '' && basename(rtrim($documentRoot,DIRECTORY_SEPARATOR)) === 'public_html') {
            $accountHome = dirname($documentRoot);
        }
        $this->paths = PrivatePathResolver::resolve($documentRoot,$accountHome);
        return $this->paths;
    }

    private function pendingState(array $paths,SetupKey $setupKey): PrivateInstallerState
    {
        return new PrivateInstallerState(
            $paths['config'] . '/INSTALLER-PENDING.json',$paths['document_root'],
            new SecretBox($setupKey->read()),'native-installer-pending-v1'
        );
    }

    private function render(string $view,array $data,int $status=200): Response
    {
        $html = (new ViewRenderer($this->applicationRoot . '/views'))->render($view,$data + ['brandName'=>'Talyvane','csrf'=>Csrf::issue()]);
        $base = Response::html($html,$status);
        $headers = $base->headers;
        $headers['cache-control'] = 'no-store, no-cache, must-revalidate, private';
        $headers['pragma'] = 'no-cache';
        $headers['content-security-policy'] = "default-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'; style-src 'self' 'unsafe-inline'";
        return new Response($html,$status,$headers);
    }

    private function plainError(string $message,int $status): Response
    {
        $html = '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Installer</title><main style="font:16px system-ui;max-width:640px;margin:12vh auto;padding:24px"><h1>Installer unavailable</h1><p>' . htmlspecialchars($message,ENT_QUOTES | ENT_SUBSTITUTE,'UTF-8') . '</p></main></html>';
        $base = Response::html($html,$status);
        $headers = $base->headers;
        $headers['cache-control'] = 'no-store, no-cache, must-revalidate, private';
        return new Response($html,$status,$headers);
    }

    private function logFailure(Throwable $error): void
    {
        try {
            $paths = $this->paths();
            PrivateErrorLogger::record($error,$paths['logs'],$paths['document_root'],bin2hex(random_bytes(16)));
        } catch (Throwable) {
            error_log('[talyvane-installer] exception=' . $error::class);
        }
    }

    private function requiredText(Request $request,string $name,int $min,int $max): string
    {
        $value = $this->field($request,$name);
        if (strlen($value) < $min || strlen($value) > $max || preg_match('/[\r\n\0]/',$value)) {
            throw new InvalidArgumentException('A required installation field is missing or invalid: ' . $name . '.');
        }
        return $value;
    }

    private function integerField(Request $request,string $name,int $min,int $max): int
    {
        $value = $this->field($request,$name);
        if (!ctype_digit($value) || (int) $value < $min || (int) $value > $max) {
            throw new InvalidArgumentException('A numeric installation field is invalid: ' . $name . '.');
        }
        return (int) $value;
    }

    private function field(Request $request,string $name): string
    {
        $value = $request->body[$name] ?? '';
        return is_string($value) ? trim($value) : '';
    }

    private function canonicalHttpsUrl(string $url,Request $request): string
    {
        $parts = parse_url($url);
        $requestHost = $request->header('host') ?? '';
        $requestParts = parse_url('https://' . $requestHost);
        if (!is_array($parts) || !is_array($requestParts) ||
            strtolower((string) ($parts['scheme'] ?? '')) !== 'https' ||
            !isset($parts['host']) || !isset($requestParts['host']) ||
            strtolower((string) $parts['host']) !== strtolower((string) $requestParts['host']) ||
            isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) ||
            !in_array((string) ($parts['path'] ?? ''),['','/'],true)) {
            throw new InvalidArgumentException('Canonical URL must be this site’s HTTPS origin without credentials, query or fragment.');
        }
        return 'https://' . strtolower((string) $parts['host']) . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
    }

    private function assertInstallableDatabase(PDO $pdo): void
    {
        $tableCount = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = "BASE TABLE"')->fetchColumn();
        if ($tableCount === 0) {
            return;
        }
        $schema = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = "schema_migrations"');
        $schema->execute();
        if ((int) $schema->fetchColumn() !== 1) {
            throw new DomainException('Use a dedicated empty database. Existing non-native tables were detected.');
        }
        $users = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = "users"');
        $users->execute();
        if ((int) $users->fetchColumn() === 1 && (int) $pdo->query('SELECT COUNT(*) FROM users WHERE status = "active"')->fetchColumn() > 0) {
            throw new DomainException('This database already contains active accounts and cannot be reused for a new installation.');
        }
        $recovery = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = "super_admin_recovery"');
        $recovery->execute();
        if ((int) $recovery->fetchColumn() === 1 && (int) $pdo->query('SELECT COUNT(*) FROM super_admin_recovery')->fetchColumn() > 0) {
            throw new DomainException('This database already has an activated Super Admin.');
        }
    }

    private function consumeSessionAttempt(): void
    {
        $window = (int) ($_SESSION['installer_attempt_window'] ?? 0);
        $attempts = (int) ($_SESSION['installer_attempts'] ?? 0);
        if ($window < time()-3600) {
            $window = time();
            $attempts = 0;
        }
        $attempts++;
        $_SESSION['installer_attempt_window'] = $window;
        $_SESSION['installer_attempts'] = $attempts;
        if ($attempts > 5) {
            throw new DomainException('Installer submissions are temporarily rate limited.');
        }
    }

    private function isHttps(): bool
    {
        return isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off';
    }

    private function relativePrivatePath(array $paths,string $file): string
    {
        $relative = str_starts_with($file,$paths['home']) ? substr($file,strlen($paths['home'])) : basename($file);
        return ltrim(str_replace(DIRECTORY_SEPARATOR,'/',$relative),'/');
    }

    private function maskEmail(string $email): string
    {
        $parts = explode('@',$email,2);
        if (count($parts) !== 2) {
            return 'verified address';
        }
        return substr($parts[0],0,1) . str_repeat('*',max(2,min(8,strlen($parts[0])-1))) . '@' . $parts[1];
    }

    private function isWithin(string $path,string $parent): bool
    {
        $path = rtrim($path,DIRECTORY_SEPARATOR);
        $parent = rtrim($parent,DIRECTORY_SEPARATOR);
        return $path === $parent || str_starts_with($path . DIRECTORY_SEPARATOR,$parent . DIRECTORY_SEPARATOR);
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    }
}
