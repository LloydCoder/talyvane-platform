<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Talyvane\Domain\Money;
use Talyvane\Domain\Booking\BookingStateMachine;
use Talyvane\Domain\Consent\ConsentGrant;
use Talyvane\Domain\Market\MarketPolicy;
use Talyvane\Domain\Membership\MembershipAccess;
use Talyvane\Domain\ModelAgency\ApplicationStateMachine;
use Talyvane\Features\FeatureAvailability;
use Talyvane\Features\FeatureRegistry;
use Talyvane\Http\Request;
use Talyvane\Http\Response;
use Talyvane\Http\Router;
use Talyvane\Security\Authorization;
use Talyvane\Security\PasswordHasher;
use Talyvane\Security\RecoveryCodes;
use Talyvane\Security\SecretBox;
use Talyvane\Security\Principal;
use Talyvane\Security\Totp;

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

fwrite(STDOUT, sprintf("Native PHP foundation: %d assertions passed.\n", $tests));
