<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Talyvane\Domain\Money;
use Talyvane\Domain\Money\Currency;
use Talyvane\Domain\Money\QuoteSnapshot;
use Talyvane\Domain\Payments\PaymentStateMachine;
use Talyvane\Domain\Booking\BookingStateMachine;
use Talyvane\Domain\Consent\ConsentGrant;
use Talyvane\Domain\Market\MarketPolicy;
use Talyvane\Domain\Membership\MembershipAccess;
use Talyvane\Domain\ModelAgency\ApplicationStateMachine;
use Talyvane\Domain\ModelAgency\AgreementStateMachine;
use Talyvane\Domain\ModelAgency\AssignmentStateMachine;
use Talyvane\Domain\ModelAgency\CastingStateMachine;
use Talyvane\Domain\ModelAgency\ImageLicencePolicy;
use Talyvane\Domain\ModelAgency\SettlementCalculator;
use Talyvane\Domain\ModelAgency\SettlementStateMachine;
use Talyvane\Features\FeatureAvailability;
use Talyvane\Features\FeatureRegistry;
use Talyvane\Http\Request;
use Talyvane\Http\Response;
use Talyvane\Http\Router;
use Talyvane\Http\ViewRenderer;
use Talyvane\Security\Authorization;
use Talyvane\Security\PasswordHasher;
use Talyvane\Security\RecoveryCodes;
use Talyvane\Security\SecretBox;
use Talyvane\Security\Principal;
use Talyvane\Security\Totp;
use Talyvane\Infrastructure\Logging\StructuredLogger;
use Talyvane\Infrastructure\Storage\PrivateMediaStorage;
use Talyvane\Infrastructure\Mail\SmtpMailer;
use Talyvane\Installer\PrivatePathResolver;
use Talyvane\Installer\EnvironmentPreflight;
use Talyvane\Installer\SetupKey;
use Talyvane\Installer\InstallerLock;
use Talyvane\Installer\PrivateConfigStore;
use Talyvane\Installer\SafeZipExtractor;

$tests = 0;
$assert = static function (bool $condition, string $message) use (&$tests): void {
    $tests++;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
};

$assert(Money::fromDecimal('123.45', 'USD', 2)->minorUnits === 12345, 'two-decimal money uses integer minor units');
$assert(Money::fromDecimal('120', 'JPY', 0)->toDecimal() === '120', 'zero-decimal currency formats correctly');
$assert(Money::fromDecimal('1.234', 'KWD', 3)->toDecimal() === '1.234', 'three-decimal currency formats correctly');
$assert(Money::fromDecimal('92233720368547758.07', 'USD', 2)->minorUnits === PHP_INT_MAX, 'maximum supported money amount is accepted exactly');
try { Money::fromDecimal('1.001', 'USD', 2); $assert(false, 'excess currency precision rejected'); }
catch (InvalidArgumentException) { $assert(true, 'excess currency precision rejected'); }
try { Money::fromDecimal('92233720368547758.08', 'USD', 2); $assert(false, 'money overflow rejected'); }
catch (InvalidArgumentException) { $assert(true, 'money overflow rejected'); }
try { Money::fromDecimal('1.00', 'USD', 2)->plus(Money::fromDecimal('1.00', 'EUR', 2)); $assert(false, 'mixed currencies rejected'); }
catch (InvalidArgumentException) { $assert(true, 'mixed currencies rejected'); }

$router = new Router();
$router->add('GET', '/health/{component}', static fn(Request $r, array $p): Response => Response::json(['component'=>$p['component']]));
$response = $router->dispatch(new Request('GET', '/health/database'));
$assert($response->status === 200 && $response->body === '{"component":"database"}', 'router extracts named path parameters');
$assert($router->dispatch(new Request('GET', '/missing'))->status === 404, 'router returns 404 for unknown routes');

$viewRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'talyvane-views-' . bin2hex(random_bytes(6));
mkdir($viewRoot . DIRECTORY_SEPARATOR . 'pages', 0700, true);
file_put_contents($viewRoot . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'sample.php', '<h1><?= $escape($title) ?></h1>');
$views = new ViewRenderer($viewRoot);
$assert($views->render('pages/sample', ['title'=>'<script>alert(1)</script>']) === '<h1>&lt;script&gt;alert(1)&lt;/script&gt;</h1>', 'view renderer HTML-escapes untrusted values');
try { $views->render('../outside'); $assert(false, 'view identifiers reject traversal'); }
catch (InvalidArgumentException) { $assert(true, 'view identifiers reject traversal'); }
try { $views->render('pages/missing'); $assert(false, 'view renderer rejects missing templates'); }
catch (RuntimeException) { $assert(true, 'view renderer rejects missing templates'); }
unlink($viewRoot . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'sample.php');
rmdir($viewRoot . DIRECTORY_SEPARATOR . 'pages');
rmdir($viewRoot);

$authViews = new ViewRenderer(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views');
$authMarkup = $authViews->render('auth/screen', [
    'screen'=>'login',
    'csrf'=>'test-csrf-token',
    'error'=>'<script>alert(1)</script>',
    'email'=>'person@example.test',
    'secret'=>'',
    'recoveryCodes'=>[],
    'roles'=>[],
]);
$assert(str_contains($authMarkup, 'name="_csrf" value="test-csrf-token"'), 'admin sign-in view includes its CSRF token');
$assert(str_contains($authMarkup, '&lt;script&gt;alert(1)&lt;/script&gt;'), 'admin sign-in view escapes authentication errors');
$assert(!str_contains($authMarkup, '<script>alert(1)</script>'), 'admin sign-in view never renders error text as markup');
$setupMarkup = $authViews->render('auth/screen', [
    'screen'=>'mfa_setup',
    'csrf'=>'setup-csrf',
    'secret'=>'<img src=x onerror=alert(1)>',
    'error'=>'',
]);
$assert(str_contains($setupMarkup, 'action="/admin/mfa/setup"'), 'MFA setup view posts to its dedicated confirmation route');
$assert(!str_contains($setupMarkup, '<img src=x onerror=alert(1)>'), 'MFA enrollment secret is escaped before rendering');
$recoveryMarkup = $authViews->render('auth/screen', [
    'screen'=>'recovery_codes',
    'csrf'=>'recovery-csrf',
    'recoveryCodes'=>['ABCDEF1234567890ABCD','<script>bad</script>'],
]);
$assert(str_contains($recoveryMarkup, 'ABCDEF1234567890ABCD'), 'one-time recovery codes are rendered for the user');
$assert(!str_contains($recoveryMarkup, '<script>bad</script>'), 'recovery code content is escaped');
$dashboardMarkup = $authViews->render('auth/screen', [
    'screen'=>'dashboard',
    'csrf'=>'dashboard-csrf',
    'roles'=>['admin','<svg onload=alert(1)>'],
]);
$assert(str_contains($dashboardMarkup, 'Access is limited') || str_contains($dashboardMarkup, 'Control room'), 'administrator dashboard screen has an explicit identity context');
$assert(!str_contains($dashboardMarkup, '<svg onload=alert(1)>'), 'administrator role labels are escaped');


try { Response::redirect('//evil.example'); $assert(false, 'external protocol-relative redirects rejected'); }
catch (InvalidArgumentException) { $assert(true, 'external protocol-relative redirects rejected'); }
try { Response::redirect("/safe\r\nLocation: https://evil.example"); $assert(false, 'CRLF redirect injection rejected'); }
catch (InvalidArgumentException) { $assert(true, 'CRLF redirect injection rejected'); }

$features = new FeatureAvailability(
    ['bookings.manual'=>true,'bookings.automatic'=>true],
    static fn(string $gate): bool => $gate !== 'market.policy'
);
$assert(!$features->enabled('bookings.manual'), 'manual bookings fail closed without approved market policy');
$assert(!FeatureRegistry::known('security.disable_authorization'), 'unknown feature keys are rejected');

$paymentFeatures = new FeatureAvailability(
    ['payments.configuration'=>true,'payments.crypto'=>true],
    static fn(string $gate): bool => $gate === 'market.policy'
);
$assert(!$paymentFeatures->enabled('payments.crypto'), 'crypto activation fails closed without provider and legal approval');
$approvedPaymentFeatures = new FeatureAvailability(
    ['payments.configuration'=>true,'payments.crypto'=>true],
    static fn(string $gate): bool => in_array($gate, ['market.policy','provider.approved','legal.approved'], true)
);
$assert($approvedPaymentFeatures->enabled('payments.crypto'), 'crypto feature enables only after all prerequisites pass');
$assert(!FeatureRegistry::known('safeguarding.adult_only'), 'hard safeguarding invariant is not an ordinary feature flag');
$assert(FeatureRegistry::known('bookings.companionship') && FeatureRegistry::known('portals.client') && FeatureRegistry::known('portals.companion'), 'native feature keys align with Laravel route gates');

$passwordHash = PasswordHasher::hash('Correct-Horse-Battery-77!');
$assert(PasswordHasher::verify('Correct-Horse-Battery-77!', $passwordHash), 'password hash verifies');
$assert(!PasswordHasher::verify('wrong-password', $passwordHash), 'wrong password rejected');

$rfcSecret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
$assert(Totp::codeAt($rfcSecret, 59, 8) === '94287082', 'TOTP follows RFC 6238 SHA-1 test vector');
$totpSecret = Totp::generateSecret();
$totpCode = Totp::codeAt($totpSecret, 300);
$assert(strlen($totpSecret) >= 32 && preg_match('/^[0-9]{6}$/D', $totpCode) === 1, 'TOTP secret and six-digit code are generated');
$assert(Totp::verify($totpSecret, $totpCode, 300) === 10, 'TOTP verifies within the current time counter');
$assert(Totp::verify($totpSecret, $totpCode, 300, 10) === null, 'TOTP replay counter is rejected');

$assert(PrivateMediaStorage::validKey('profile-media/0123456789abcdef0123456789abcdef.webp'), 'private media accepts opaque generated storage keys');
$assert(!PrivateMediaStorage::validKey('../public_html/secret.webp'), 'private media rejects path traversal keys');
$assert(!PrivateMediaStorage::validKey('model-portfolio/user-uploaded-name.jpg'), 'private media rejects user-controlled filenames');

$smtp = new SmtpMailer(['host'=>'smtp.example.test','port'=>587,'username'=>'user','password'=>'test-only','from_email'=>'noreply@example.test','from_name'=>'Talyvane','encryption'=>'tls']);
try { $smtp->send('person@example.test',"Subject\r\nBcc: attacker@example.test",'Body'); $assert(false,'SMTP header injection is rejected'); }
catch (InvalidArgumentException) { $assert(true,'SMTP header injection is rejected'); }
try { new SmtpMailer(['host'=>'smtp.example.test','port'=>587,'username'=>'user','password'=>'test-only','from_email'=>'noreply@example.test','encryption'=>'none']); $assert(false,'SMTP encryption downgrade is rejected'); }
catch (InvalidArgumentException) { $assert(true,'SMTP encryption downgrade is rejected'); }

$redacted = StructuredLogger::redact(['smtpPassword'=>'do-not-log','nested'=>['api_key'=>'do-not-log-either','status'=>'failed']]);
$assert($redacted['smtpPassword'] === '[REDACTED]' && $redacted['nested']['api_key'] === '[REDACTED]', 'structured logger redacts nested secrets');
$assert($redacted['nested']['status'] === 'failed', 'structured logger preserves non-sensitive diagnostics');

$box = new SecretBox(str_repeat('k', 32));
$encrypted = $box->encrypt('sensitive-provider-secret', 'provider:payments');
$assert($box->decrypt($encrypted, 'provider:payments') === 'sensitive-provider-secret', 'encrypted secrets decrypt only with matching context');
try { $box->decrypt($encrypted, 'provider:mail'); $assert(false, 'secret context binding enforced'); }
catch (RuntimeException) { $assert(true, 'secret context binding enforced'); }

$recovery = RecoveryCodes::issue(8);
$remaining = RecoveryCodes::consume($recovery['plain'][0], $recovery['hashes']);
$assert(count($recovery['plain']) === 8 && is_array($remaining) && count($remaining) === 7, 'recovery code is consumed once');
$assert(RecoveryCodes::consume($recovery['plain'][0], $remaining) === null, 'consumed recovery code cannot be reused');

$finance = new Principal('finance-1', ['finance'], true);
$assert(Authorization::allows($finance, 'model_settlements.view'), 'finance role can access settlement workspace');
$assert(!Authorization::allows($finance, 'dashboard.view'), 'finance role cannot access general dashboard');
$assert(!Authorization::allows(new Principal('finance-2', ['finance'], false), 'model_settlements.view'), 'privileged access requires MFA');
$verifier = new Principal('verifier-1', ['verifier'], true);
$assert(Authorization::allows($verifier, 'model_applications.adult_eligibility.verify'), 'verifier can perform single-purpose eligibility review');
$assert(!Authorization::allows($verifier, 'model_applications.manage'), 'verifier cannot administer applications');
$safeguarding = new Principal('safeguarding-1', ['safeguarding'], true);
$assert(Authorization::allows($safeguarding, 'safeguarding.cases.assigned.view'), 'safeguarding specialist can access assigned case scope');
$assert(!Authorization::allows($safeguarding, 'bookings.manage'), 'safeguarding specialist cannot access bookings');
$superAdmin = new Principal('super-1', ['super_admin'], true);
$assert(!Authorization::allows($superAdmin, 'security.authorization.bypass'), 'even Super Admin cannot bypass authorization invariant');

$now = new DateTimeImmutable('2026-11-25T12:00:00+00:00');
$approvedMarket = new MarketPolicy('GB', true, 'approved', 'reviewer-1', $now->modify('-1 day'), $now->modify('+30 days'), 'evidence-ref-1', 'Counsel review recorded');
$assert($approvedMarket->operationalAt($now), 'market requires active approval and review evidence');
$pendingMarket = new MarketPolicy('NG', true, 'pending', null, null, null, null, null);
$assert(!$pendingMarket->operationalAt($now), 'pending market fails closed');

$membership = new MembershipAccess('active', $now->modify('-2 days'), $now->modify('+2 days'), 'private_gallery', $now->modify('-1 day'), $now->modify('+1 day'), true);
$assert($membership->allows('private_gallery', $now), 'active membership and grant permit matching entitlement');
$assert(!$membership->allows('priority_concierge', $now), 'membership tier does not grant unrelated entitlement');
$assert(!$membership->allows('private_gallery', $now->modify('+2 days')), 'expired grant denies private content');

$consent = new ConsentGrant('talent-1', 'portfolio.display', 'public', 'v3', true, $now->modify('-1 day'), null);
$assert($consent->validFor('talent-1','portfolio.display','public','v3',$now), 'current purpose-bound consent is valid');
$assert(!$consent->validFor('talent-1','portfolio.display','members','v3',$now), 'consent does not cross distribution audiences');
$assert(!$consent->validFor('talent-1','portfolio.display','public','v4',$now), 'stale consent policy version is rejected');

$assert(BookingStateMachine::transition('requested','accepted','companion') === 'accepted', 'companion can accept an eligible booking request');
try { BookingStateMachine::transition('requested','accepted','client'); $assert(false, 'client cannot accept booking for provider'); }
catch (DomainException) { $assert(true, 'client cannot accept booking for provider'); }
try { BookingStateMachine::transition('completed','confirmed','admin'); $assert(false, 'terminal booking state cannot reopen'); }
catch (DomainException) { $assert(true, 'terminal booking state cannot reopen'); }

$assert(ApplicationStateMachine::transition('submitted','under_review','admin') === 'under_review', 'admin can move application into review');
$assert(ApplicationStateMachine::transition('accepted','withdrawn','applicant') === 'withdrawn', 'applicant can withdraw their application');
try { ApplicationStateMachine::transition('submitted','accepted','applicant'); $assert(false, 'applicant cannot self-approve application'); }
catch (DomainException) { $assert(true, 'applicant cannot self-approve application'); }

$currency = new Currency('KWD','Kuwaiti dinar',3,true,'414','د.ك','ISO-4217-2026');
$assert($currency->minorUnits === 3 && $currency->enabled, 'currency reference supports three-decimal currency precision');
$quote = new QuoteSnapshot(10000,'GBP',12500,'USD','1.25','rate-provider',$now->modify('-1 minute'),$now->modify('+5 minutes'),null,[],'GB','market-v1',$now->modify('+10 minutes'));
$acceptedQuote = $quote->accept($now);
$assert($acceptedQuote->acceptedAt === $now && $acceptedQuote->originalCurrency === 'GBP', 'accepted quote preserves immutable original terms and timestamp');
try { $acceptedQuote->accept($now); $assert(false, 'quote cannot be accepted twice'); }
catch (DomainException) { $assert(true, 'quote cannot be accepted twice'); }
try { (new QuoteSnapshot(100,'GBP',100,'USD','0','rate-provider',$now,$now->modify('+1 minute'),null,[],'GB','v1',$now->modify('+2 minutes'))); $assert(false, 'zero exchange rate rejected'); }
catch (InvalidArgumentException) { $assert(true, 'zero exchange rate rejected'); }
try { $quote->accept($now->modify('+11 minutes')); $assert(false, 'expired quote cannot be accepted'); }
catch (DomainException) { $assert(true, 'expired quote cannot be accepted'); }
$assert(PaymentStateMachine::transition('confirming','confirmed','provider','chain-tx-reference') === 'confirmed', 'payment confirmation requires provider evidence');
try { PaymentStateMachine::transition('pending','confirmed','client',''); $assert(false, 'client cannot self-confirm payment'); }
catch (DomainException) { $assert(true, 'client cannot self-confirm payment'); }
try { PaymentStateMachine::transition('confirmed','settled','finance'); $assert(false, 'settlement requires external evidence'); }
catch (DomainException) { $assert(true, 'settlement requires external evidence'); }

$assert(AgreementStateMachine::transition('offered','executed','admin','external-agreement-ref') === 'executed', 'agreement execution requires external evidence');
try { AgreementStateMachine::transition('offered','executed','admin'); $assert(false, 'agreement cannot be marked executed without evidence'); }
catch (DomainException) { $assert(true, 'agreement cannot be marked executed without evidence'); }
$assert(CastingStateMachine::submission('submitted','selected','admin') === 'selected', 'agency can select a casting submission');
try { CastingStateMachine::submission('submitted','selected','applicant'); $assert(false, 'applicant cannot select themselves'); }
catch (DomainException) { $assert(true, 'applicant cannot select themselves'); }
$assert(AssignmentStateMachine::transition('offered','accepted','applicant','acceptance-evidence') === 'accepted', 'talent acceptance requires evidence');
try { AssignmentStateMachine::transition('offered','confirmed','applicant','agency-1'); $assert(false, 'talent cannot confirm agency assignment'); }
catch (DomainException) { $assert(true, 'talent cannot confirm agency assignment'); }
$licence = new ImageLicencePolicy('active','portfolio.display','GB','public',true,true,true,'execution-ref',$now->modify('-1 day'),$now->modify('+1 day'),null);
$assert($licence->permits('portfolio.display','GB','public',$now), 'image licence permits only current approved purpose and audience');
$assert(!$licence->permits('advertising','GB','public',$now), 'image licence cannot be reused for another purpose');
$calculation = SettlementCalculator::calculate(100000,20000,10000,2000,'GBP');
$assert($calculation['commission_minor'] === 20000 && $calculation['model_net_minor'] === 70000, 'settlement uses exact integer minor-unit commission');
try { SettlementCalculator::calculate(100000,5000,5001,2000,'GBP'); $assert(false, 'expense cap enforced'); }
catch (DomainException) { $assert(true, 'expense cap enforced'); }
try { SettlementCalculator::calculate(1000,1000,1000,2000,'GBP'); $assert(false, 'negative settlement net rejected'); }
catch (DomainException) { $assert(true, 'negative settlement net rejected'); }
try { SettlementStateMachine::transition('draft','approved','finance','maker-1','maker-1'); $assert(false, 'settlement creator cannot approve own settlement'); }
catch (DomainException) { $assert(true, 'settlement creator cannot approve own settlement'); }
try { SettlementStateMachine::transition('approved','paid','finance','maker-1','approver-1','approver-1','external-payment-ref'); $assert(false, 'settlement approver cannot attest own payment'); }
catch (DomainException) { $assert(true, 'settlement approver cannot attest own payment'); }
$assert(SettlementStateMachine::transition('approved','paid','finance','maker-1','payer-1','approver-1','external-payment-ref') === 'paid', 'payment attestation requires distinct actors and external evidence');

$tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'talyvane-native-' . bin2hex(random_bytes(6));
$accountHome = $tempRoot . DIRECTORY_SEPARATOR . 'account';
$documentRoot = $accountHome . DIRECTORY_SEPARATOR . 'public_html';
mkdir($documentRoot, 0700, true);
$privatePaths = PrivatePathResolver::resolve($documentRoot, $accountHome);
$assert(is_dir($privatePaths['application']) && is_dir($privatePaths['logs']), 'cPanel private path resolver creates private application and log directories');
$assert(!str_starts_with($privatePaths['application'], $privatePaths['document_root'] . DIRECTORY_SEPARATOR), 'private application directory is outside public document root');
$configStore = new PrivateConfigStore($privatePaths['config'] . DIRECTORY_SEPARATOR . 'app.php',$privatePaths['document_root']);
$configStore->create(['app_key'=>str_repeat('A',32),'database'=>['host'=>'127.0.0.1','database'=>'test']]);
$loadedConfig = $configStore->load();
$assert(($loadedConfig['database']['database'] ?? '') === 'test', 'private config is written and loaded outside the public root');
try { $configStore->create(['app_key'=>str_repeat('B',32),'database'=>[]]); $assert(false, 'private config cannot be overwritten'); }
catch (RuntimeException) { $assert(true, 'private config cannot be overwritten'); }
$setupKey = new SetupKey($privatePaths['config'] . DIRECTORY_SEPARATOR . 'installer.key');
$plainKey = $setupKey->create();
$assert($setupKey->verify($plainKey), 'one-time installer key verifies from private file');
$assert(!$setupKey->verify('invalid'), 'invalid installer key is rejected');
$lock = new InstallerLock($privatePaths['config'] . DIRECTORY_SEPARATOR . 'installed.lock');
$assert(!$lock->isLocked(), 'new installation begins unlocked');
$lock->lock('12345678-1234-4234-8234-123456789abc');
$assert($lock->isLocked(), 'permanent installer lock is written and verified');
try { $lock->lock('12345678-1234-4234-8234-123456789abc'); $assert(false, 'installer lock cannot be overwritten'); }
catch (RuntimeException) { $assert(true, 'installer lock cannot be overwritten'); }
$setupKey->consume();
$assert(!$setupKey->verify($plainKey), 'installer key is consumed after lock');
$preflight = EnvironmentPreflight::inspect($accountHome);
$assert($preflight['checks']['private_writable']['ok'], 'installer preflight checks the supplied account home');
if (class_exists(ZipArchive::class)) {
    $safeArchivePath = $tempRoot . DIRECTORY_SEPARATOR . 'safe.zip';
    $safeArchive = new ZipArchive();
    $safeArchive->open($safeArchivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $safeArchive->addFromString('assets/check.txt','safe payload');
    $safeArchive->close();
    $extracted = SafeZipExtractor::extract($safeArchivePath,$privatePaths['storage'] . DIRECTORY_SEPARATOR . 'payload',$privatePaths['document_root']);
    $assert($extracted['files'] === 1 && file_get_contents($privatePaths['storage'] . '/payload/assets/check.txt') === 'safe payload','safe ZIP payload extracts outside public root');
    $badArchivePath = $tempRoot . DIRECTORY_SEPARATOR . 'bad.zip';
    $badArchive = new ZipArchive();
    $badArchive->open($badArchivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $badArchive->addFromString('../escape.txt','blocked');
    $badArchive->close();
    try { SafeZipExtractor::extract($badArchivePath,$privatePaths['storage'] . DIRECTORY_SEPARATOR . 'bad-payload',$privatePaths['document_root']); $assert(false,'ZIP path traversal is rejected'); }
    catch (RuntimeException) { $assert(true,'ZIP path traversal is rejected'); }
    $assert(!is_file($privatePaths['storage'] . '/escape.txt'),'ZIP traversal did not create an escaped file');
}
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tempRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($iterator as $entry) {
    $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
}
rmdir($tempRoot);

fwrite(STDOUT, sprintf("Native PHP foundation: %d assertions passed.\n", $tests));
