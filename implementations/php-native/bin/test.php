<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Talyvane\Domain\Money;
use Talyvane\Application\Destinations\DestinationCoverService;
use Talyvane\Domain\Money\Currency;
use Talyvane\Domain\Money\QuoteSnapshot;
use Talyvane\Domain\Payments\PaymentStateMachine;
use Talyvane\Domain\Booking\BookingStateMachine;
use Talyvane\Domain\Booking\BookingCancellationPolicy;
use Talyvane\Application\Bookings\BookingCalendarService;
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
use Talyvane\Infrastructure\Logging\PrivateErrorLogger;
use Talyvane\Infrastructure\Database\MigrationRunner;
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

$cancelPolicy = new BookingCancellationPolicy(true, 120);
$cancelPolicy->assertAllowed('client','confirmed',new DateTimeImmutable('2026-12-01T12:00:00+00:00'),new DateTimeImmutable('2026-12-01T08:00:00+00:00'));
$assert(true, 'client may cancel a confirmed booking outside the configured cutoff');
try { $cancelPolicy->assertAllowed('client','confirmed',new DateTimeImmutable('2026-12-01T09:00:00+00:00'),new DateTimeImmutable('2026-12-01T08:00:00+00:00')); $assert(false, 'client cancellation inside cutoff is rejected'); }
catch (DomainException) { $assert(true, 'client cancellation inside cutoff is rejected'); }
$cancelPolicyDisabled = new BookingCancellationPolicy(false,120);
$cancelPolicyDisabled->assertAllowed('client','pending_review',new DateTimeImmutable('2026-12-02T12:00:00+00:00'),new DateTimeImmutable('2026-12-01T08:00:00+00:00'));
$assert(true, 'client may cancel a pending review request without confirmed-booking cancellation policy');
$cancelPolicyDisabled->assertAllowed('client','requested',new DateTimeImmutable('2026-12-02T12:00:00+00:00'),new DateTimeImmutable('2026-12-01T08:00:00+00:00'));
$assert(true, 'client may cancel a newly submitted requested booking without confirmed-booking cancellation policy');
try { $cancelPolicyDisabled->assertAllowed('client','confirmed',new DateTimeImmutable('2026-12-02T12:00:00+00:00'),new DateTimeImmutable('2026-12-01T08:00:00+00:00')); $assert(false, 'disabled confirmed-booking cancellation is rejected'); }
catch (DomainException) { $assert(true, 'disabled client cancellation is rejected'); }
$cancelPolicyDisabled->assertAllowed('client','accepted',new DateTimeImmutable('2026-12-02T12:00:00+00:00'),new DateTimeImmutable('2026-12-01T08:00:00+00:00'));
$assert(true, 'client may cancel a companion-accepted booking before administrator confirmation');
try { $cancelPolicy->assertAllowed('client','declined',new DateTimeImmutable('2026-12-02T12:00:00+00:00'),new DateTimeImmutable('2026-12-01T08:00:00+00:00')); $assert(false, 'terminal declined booking cannot be cancelled'); }
catch (DomainException) { $assert(true, 'terminal declined booking cannot be cancelled'); }
try { $cancelPolicy->assertAllowed('companion','confirmed',new DateTimeImmutable('2026-12-02T12:00:00+00:00'),new DateTimeImmutable('2026-12-01T08:00:00+00:00')); $assert(false, 'companion cannot use client cancellation policy'); }
catch (DomainException) { $assert(true, 'companion cannot use client cancellation policy'); }

$assert(BookingStateMachine::transition('pending_review','cancelled','client') === 'cancelled', 'client may cancel a pending-review booking');
$assert(BookingStateMachine::transition('requested','cancelled','client') === 'cancelled', 'client may cancel a newly requested booking');
$assert(BookingStateMachine::transition('accepted','cancelled','client') === 'cancelled', 'client may cancel before administrator confirmation');

$calendarPdo = new class extends PDO { public function __construct() {} };
$calendar = new BookingCalendarService($calendarPdo,str_repeat('C',32));
$calendarNow = new DateTimeImmutable('2026-11-26T09:00:00+00:00');
$calendarEvents = [[
    'id'=>'booking-private-1','status'=>'confirmed',
    'starts_at_utc'=>'2026-12-01 12:00:00','ends_at_utc'=>'2026-12-01 13:00:00',
    'updated_at'=>'2026-11-26 08:00:00','profile_name'=>"Alex, Example\nVIP",
    'destination_name'=>'London; United Kingdom',
    'client_note'=>'PRIVATE NOTE MUST NOT APPEAR','client_user_id'=>'client-secret-id',
]];
$clientCalendar = $calendar->toIcalendar(new Principal('client-calendar-id',['client'],false),$calendarEvents,$calendarNow);
$assert(str_contains($clientCalendar,'BEGIN:VCALENDAR') && str_contains($clientCalendar,'DTSTART:20261201T120000Z'), 'calendar export emits RFC5545 UTC event times');
$assert(str_contains($clientCalendar,'SUMMARY:Booking with Alex\\, Example\\nVIP — London\\; United Kingdom') && !str_contains($clientCalendar,'PRIVATE NOTE') && !str_contains($clientCalendar,'client-secret-id'), 'client calendar escapes text and excludes notes and account identifiers');
$companionCalendar = $calendar->toIcalendar(new Principal('companion-calendar-id',['companion'],false),$calendarEvents,$calendarNow);
$assert(str_contains($companionCalendar,'SUMMARY:Appointment — London\\; United Kingdom') && !str_contains($companionCalendar,'Alex'), 'companion calendar projection excludes client-facing profile identity details');
$cancelledCalendar = $calendar->toIcalendar(new Principal('client-calendar-id',['client'],false),[array_merge($calendarEvents[0],['status'=>'cancelled'])],$calendarNow);
$assert(str_contains($cancelledCalendar,'STATUS:CANCELLED'), 'calendar export carries cancellation tombstones for synchronization clients');
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
$router->add('GET','/portal/membership',static fn(Request $r,array $p): Response => Response::html('private membership'));
$router->add('GET','/public-page',static fn(Request $r,array $p): Response => Response::html('public page'));
$privateResponse=$router->dispatch(new Request('GET','/portal/membership'));
$assert(str_contains($privateResponse->headers['cache-control']??'','no-store') &&
    ($privateResponse->headers['pragma']??'')==='no-cache',
    'client and companion portal responses are explicitly non-cacheable');
$publicResponse=$router->dispatch(new Request('GET','/public-page'));
$assert(!isset($publicResponse->headers['cache-control']),
    'public HTML responses retain their independently configurable cache policy');
$uploadRequest = new Request('POST','/upload',[],[],[],'127.0.0.1','upload-request',[
    'media'=>['name'=>'portrait.webp','tmp_name'=>'/tmp/php-upload','error'=>UPLOAD_ERR_OK,'size'=>128,'type'=>'image/webp'],
]);
$assert(($uploadRequest->files['media']['name'] ?? '') === 'portrait.webp', 'native request abstraction preserves uploaded file metadata separately from form fields');

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
    'brandName'=>'Maison Example',
    'csrf'=>'test-csrf-token',
    'error'=>'<script>alert(1)</script>',
    'email'=>'person@example.test',
    'secret'=>'',
    'recoveryCodes'=>[],
    'roles'=>[],
]);
$assert(str_contains($authMarkup, 'name="_csrf" value="test-csrf-token"'), 'admin sign-in view includes its CSRF token');
$assert(str_contains($authMarkup, 'Maison Example'), 'admin sign-in uses the configured white-label brand');
$assert(str_contains($authMarkup, '&lt;script&gt;alert(1)&lt;/script&gt;'), 'admin sign-in view escapes authentication errors');
$assert(!str_contains($authMarkup, '<script>alert(1)</script>'), 'admin sign-in view never renders error text as markup');
$portalRegisterMarkup = $authViews->render('auth/portal', [
    'screen'=>'register',
    'brandName'=>'Maison Example',
    'csrf'=>'portal-csrf',
    'email'=>'person@example.test',
    'error'=>'<script>alert(1)</script>',
]);
$assert(str_contains($portalRegisterMarkup, 'action="/portal/register"'), 'public portal registration posts to its dedicated endpoint');
$assert(str_contains($portalRegisterMarkup, 'name="account_type"'), 'public registration requires an explicit client or companion account type');
$assert(str_contains($portalRegisterMarkup, 'name="_csrf" value="portal-csrf"'), 'public portal registration includes CSRF protection');
$assert(!str_contains($portalRegisterMarkup, '<script>alert(1)</script>'), 'public portal registration escapes reflected errors');
$portalVerifyMarkup = $authViews->render('auth/portal', [
    'screen'=>'verify',
    'csrf'=>'verify-csrf',
    'email'=>'person@example.test',
]);
$assert(str_contains($portalVerifyMarkup, 'action="/portal/verify"') && str_contains($portalVerifyMarkup, 'pattern="[0-9]{6}"'), 'portal email verification requires a six-digit code');
$portalRecoveryMarkup = $authViews->render('auth/portal', [
    'screen'=>'recovery_request',
    'csrf'=>'recovery-request-csrf',
    'email'=>'person@example.test',
]);
$assert(str_contains($portalRecoveryMarkup, 'action="/portal/recovery"'), 'portal password recovery request posts to its dedicated endpoint');
$portalRecoveryCompleteMarkup = $authViews->render('auth/portal', [
    'screen'=>'recovery_complete',
    'csrf'=>'recovery-complete-csrf',
    'email'=>'person@example.test',
]);
$assert(str_contains($portalRecoveryCompleteMarkup, 'action="/portal/recovery/complete"'), 'portal password recovery requires the one-time code and new password');
$assert(str_contains($portalRecoveryCompleteMarkup, 'name="code"') && str_contains($portalRecoveryCompleteMarkup, 'name="new_password"'), 'portal recovery form collects a verification code and new password');

$portalLoginMarkup = $authViews->render('auth/portal', [
    'screen'=>'login',
    'csrf'=>'portal-login-csrf',
    'email'=>'person@example.test',
]);
$assert(str_contains($portalLoginMarkup, 'action="/portal/login"'), 'portal login is distinct from administrator authentication');
$assert(str_contains($portalLoginMarkup, 'href="/portal/recovery"'), 'portal login exposes account recovery without revealing account existence');
$portalDashboardMarkup = $authViews->render('auth/portal', [
    'screen'=>'dashboard',
    'csrf'=>'portal-dashboard-csrf',
    'accountType'=>'companion',
]);
$assert(str_contains($portalDashboardMarkup, 'Companion workspace'), 'companion portal is visibly separated from the client portal');
$assert(str_contains($portalDashboardMarkup, 'action="/portal/logout"'), 'portal dashboard exposes CSRF-protected sign-out');
$assert(str_contains($portalDashboardMarkup,'href="/portal/security"'),'client and companion dashboards expose account security');
$assert(str_contains($portalDashboardMarkup,'href="/portal/privacy/export"'),'client and companion dashboards expose a self-service account data export');
$clientMembershipMarkup = $authViews->render('auth/portal', [
    'screen'=>'dashboard',
    'csrf'=>'portal-client-membership-csrf',
    'accountType'=>'client',
    'membership'=>[
        'tier_name'=>'<script>alert(1)</script>',
        'plan_key'=>'gold',
        'benefits_summary'=>'Verified member benefit summary',
        'starts_at'=>'2026-11-25 00:00:00',
        'ends_at'=>'2026-12-25 00:00:00',
        'entitlements'=>['media.private_gallery'],
    ],
]);
$assert(str_contains($clientMembershipMarkup,'Your membership') &&
    str_contains($clientMembershipMarkup,'media.private_gallery') &&
    str_contains($clientMembershipMarkup,'&lt;script&gt;alert(1)&lt;/script&gt;'),
    'client dashboard renders a safe membership summary and only the effective entitlement keys');
$assert(!str_contains($clientMembershipMarkup,'<script>alert(1)</script>'),
    'membership summary fields are HTML-escaped');
$portalSecurityMarkup = $authViews->render('auth/portal-security',[
    'brandName'=>'Maison Example','csrf'=>'portal-security-csrf','error'=>'','notice'=>'',
]);
$assert(str_contains($portalSecurityMarkup,'action="/portal/security"') &&
    str_contains($portalSecurityMarkup,'name="current_password"') &&
    str_contains($portalSecurityMarkup,'name="new_password"') &&
    str_contains($portalSecurityMarkup,'name="confirm_password"'),
    'portal security screen requires current password and confirmed new password');

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
$assert(str_contains($dashboardMarkup, 'href="/admin/content"'), 'authorised administrator dashboard links to the CMS workspace');
$assert(str_contains($dashboardMarkup, 'href="/admin/companions"'), 'authorised administrator dashboard links to companion profile review');
$superAdminDashboardMarkup = $authViews->render('auth/screen', [
    'screen'=>'dashboard',
    'csrf'=>'superadmin-csrf',
    'roles'=>['super_admin'],
]);
$assert(str_contains($superAdminDashboardMarkup, 'href="/admin/brand"'), 'Super Admin dashboard exposes brand configuration');
$contentListMarkup = $authViews->render('admin/content', [
    'mode'=>'list',
    'brandName'=>'Maison Example',
    'csrf'=>'cms-csrf',
    'error'=>'',
    'pages'=>[['id'=>'page-1','slug'=>'terms','title'=>'<script>alert(1)</script>','locale'=>'en','status'=>'draft','version'=>2,'updated_at'=>'2026-11-25 10:00:00']],
    'page'=>[],
]);
$assert(str_contains($contentListMarkup, 'href="/admin/content/new"'), 'CMS index exposes the create-page workflow');
$assert(str_contains($contentListMarkup, 'Maison Example'), 'CMS workspace uses the configured white-label brand');
$assert(!str_contains($contentListMarkup, '<script>alert(1)</script>'), 'CMS index escapes untrusted page titles');
$contentEditMarkup = $authViews->render('admin/content', [
    'mode'=>'edit',
    'csrf'=>'cms-edit-csrf',
    'error'=>'',
    'pages'=>[],
    'page'=>['id'=>'page-1','slug'=>'privacy','title'=>'Privacy','locale'=>'en','content'=>'<script>unsafe</script>','meta_title'=>'','meta_description'=>'','status'=>'published','version'=>2,'published_version'=>1],
]);
$assert(str_contains($contentEditMarkup, 'name="content"'), 'CMS editor exposes the page content field');
$assert(!str_contains($contentEditMarkup, '<script>unsafe</script>'), 'CMS editor escapes stored page content in its textarea');
$brandMarkup = $authViews->render('admin/brand', [
    'csrf'=>'brand-csrf',
    'error'=>'',
    'saved'=>false,
    'brand'=>[
        'brand_name'=>'Maison Example',
        'tagline'=>'Considered representation',
        'color_tokens'=>['primary'=>'#17233B','accent'=>'#C58B43','surface'=>'#F7F5F0'],
        'typography'=>['heading'=>'Georgia','body'=>'Inter'],
        'contact_details'=>['email'=>'hello@example.test','phone'=>'+1 555 0100','website'=>'https://example.test'],
        'social_links'=>['instagram'=>'https://instagram.com/example'],
        'seo_defaults'=>['title_suffix'=>' | Maison Example','robots'=>'index,follow'],
        'logo_storage_key'=>'private/brand/logo.webp',
        'favicon_storage_key'=>null,
        'version'=>3,
    ],
]);
$assert(str_contains($brandMarkup, 'action="/admin/brand"'), 'brand settings form posts to the dedicated Super Admin route');
$assert(str_contains($brandMarkup, 'Maison Example'), 'brand settings form shows the active deployment identity');
$assert(str_contains($brandMarkup, 'name="color_accent"'), 'brand settings expose the configured accent token');
$assert(!str_contains($brandMarkup, 'private/brand/logo.webp'), 'brand settings never expose private media storage keys');
$clientBookingsMarkup = $authViews->render('portal/bookings', [
    'mode'=>'client-list',
    'brandName'=>'Maison Example',
    'csrf'=>'client-booking-csrf',
    'items'=>[['id'=>'booking-1','booking_reference'=>'TVN-ABC123','profile_name'=>'Talent Example','destination_name'=>'London','starts_at_utc'=>'2026-11-26 10:00:00','ends_at_utc'=>'2026-11-26 11:00:00','time_zone'=>'Europe/London','status'=>'requested','deposit_status'=>'not_required']],
]);
$assert(str_contains($clientBookingsMarkup, '/portal/bookings/booking-1/cancel'), 'client booking history exposes an ownership-scoped cancellation action');
$companionBookingsMarkup = $authViews->render('portal/bookings', [
    'mode'=>'companion-list',
    'brandName'=>'Maison Example',
    'csrf'=>'companion-booking-csrf',
    'items'=>[['id'=>'booking-1','booking_reference'=>'TVN-ABC123','profile_name'=>'Talent Example','destination_name'=>'London','starts_at_utc'=>'2026-11-26 10:00:00','ends_at_utc'=>'2026-11-26 11:00:00','time_zone'=>'Europe/London','status'=>'requested','deposit_status'=>'not_required']],
]);
$assert(str_contains($companionBookingsMarkup, '/portal/companion/bookings/booking-1/decision'), 'companion booking workspace exposes accept and decline decisions');
$adminBookingsMarkup = $authViews->render('portal/bookings', [
    'mode'=>'admin-list',
    'brandName'=>'Maison Example',
    'csrf'=>'admin-booking-csrf',
    'items'=>[['id'=>'booking-1','booking_reference'=>'TVN-ABC123','client_user_id'=>'internal-user-id','profile_name'=>'Talent Example','destination_name'=>'London','starts_at_utc'=>'2026-11-26 10:00:00','ends_at_utc'=>'2026-11-26 11:00:00','time_zone'=>'Europe/London','status'=>'requested','deposit_status'=>'not_required']],
]);
$assert(str_contains($adminBookingsMarkup, '/admin/bookings/booking-1/status'), 'administrator booking operations have a dedicated transition form');
$bookingNewMarkup = $authViews->render('portal/bookings', [
    'mode'=>'client-new',
    'brandName'=>'Maison Example',
    'csrf'=>'new-booking-csrf',
    'profile'=>['id'=>'profile-1','slug'=>'talent-one','display_name'=>'Talent Example','destination_name'=>'London','time_zone'=>'Europe/London'],
]);
$assert(str_contains($bookingNewMarkup, 'destination local time') && str_contains($bookingNewMarkup, 'name="starts_at"'), 'booking requests capture destination-local start and end times');

$rightsRequestMarkup = $authViews->render('public/model-agency-rights', [
    'screen'=>'request',
    'csrf'=>'rights-request-csrf',
]);
$assert(str_contains($rightsRequestMarkup, 'action="/model-agency/privacy"'), 'model applicant privacy rights start through a dedicated endpoint');
$assert(str_contains($rightsRequestMarkup, 'name="request_type"'), 'model applicant can choose access correction withdrawal or erasure');
$rightsVerifyMarkup = $authViews->render('public/model-agency-rights', [
    'screen'=>'verify',
    'csrf'=>'rights-verify-csrf',
]);
$assert(str_contains($rightsVerifyMarkup, 'pattern="[0-9]{8}"'), 'model applicant rights require an eight-digit email verification code');
$rightsEraseMarkup = $authViews->render('public/model-agency-rights', [
    'screen'=>'confirm',
    'requestType'=>'erasure',
    'csrf'=>'rights-erase-csrf',
]);
$assert(str_contains($rightsEraseMarkup, 'action="/model-agency/privacy/erase"'), 'verified applicant erasure requires a separate CSRF-protected confirmation');

$modelAgencyMarkup = $authViews->render('public/model-agency', [
    'screen'=>'form',
    'csrf'=>'model-agency-csrf',
    'available'=>true,
    'privacyVersion'=>'privacy-v4',
    'error'=>'',
    'values'=>[],
]);
$assert(str_contains($modelAgencyMarkup, 'action="/model-agency/apply"'), 'model-agency intake has its own public application route');
$assert(str_contains($modelAgencyMarkup, 'name="adult_attested"') && str_contains($modelAgencyMarkup, 'name="privacy_acknowledged"'), 'model-agency intake requires adult attestation and explicit privacy acknowledgement');
$assert(!str_contains($modelAgencyMarkup, 'identity_document') && !str_contains($modelAgencyMarkup, 'date_of_birth'), 'model-agency intake does not collect identity documents or dates of birth');
$modelAgencyAdminMarkup = $authViews->render('admin/model-agency', [
    'mode'=>'verifications',
    'brandName'=>'Maison Example',
    'csrf'=>'verifier-csrf',
    'error'=>'',
    'items'=>[['id'=>'application-1','application_reference'=>'MOD-ABC123','country_code'=>'GB','city'=>'London','status'=>'under_review','latest_verification_outcome'=>null]],
]);
$assert(str_contains($modelAgencyAdminMarkup, '/admin/model-agency/application-1/verify'), 'adult verifier queue provides a decision workflow');

$assert(!str_contains($modelAgencyAdminMarkup, 'applicant@example.test') && !str_contains($modelAgencyAdminMarkup, 'Applicant Name'), 'verifier workspace does not expose applicant contact or name fields');
$modelAgencyReviewMarkup = $authViews->render('admin/model-agency', [
    'mode'=>'applications',
    'brandName'=>'Maison Example',
    'csrf'=>'agency-review-csrf',
    'error'=>'',
    'items'=>[['id'=>'application-1','application_reference'=>'MOD-ABC123','applicant_name'=>'Applicant Example','email'=>'applicant@example.test','phone'=>null,'country_code'=>'GB','city'=>'London','portfolio_url'=>null,'status'=>'under_review','legal_hold'=>0,'created_at'=>'2026-11-25 09:00:00','latest_verification_outcome'=>'verified']],
]);
$assert(str_contains($modelAgencyReviewMarkup, '/admin/model-agency/application-1/legal-hold'), 'MFA-authorized agency administration exposes legal-hold controls');


$discoveryMarkup = $authViews->render('public/discovery', [
    'mode'=>'destination',
    'brandName'=>'Maison Example',
    'destination'=>['slug'=>'london','display_name'=>'London'],
    'profiles'=>[['slug'=>'talent-one','display_name'=>'<script>alert(1)</script>','experience_category'=>'Representation']],
]);
$assert(str_contains($discoveryMarkup, 'href="/companions/talent-one"'), 'public discovery links to published profiles');
$assert(!str_contains($discoveryMarkup, '<script>alert(1)</script>'), 'public discovery escapes profile data');
$companionsMarkup = $authViews->render('public/discovery', [
    'mode'=>'companions','brandName'=>'Maison Example',
    'profiles'=>[['slug'=>'talent-one','display_name'=>'<script>alert(1)</script>','destination_name'=>'London','experience_category'=>'Representation','primary_media_url'=>null]],
]);
$assert(str_contains($companionsMarkup,'Explore companions') && str_contains($companionsMarkup,'href="/companions/talent-one"') &&
    !str_contains($companionsMarkup,'<script>alert(1)</script>'),
    'global companion directory uses canonical companion routes and escapes profile content');
$adminCompanionMarkup = $authViews->render('admin/companions', [
    'brandName'=>'Maison Example',
    'csrf'=>'companion-review-csrf',
    'error'=>'',
    'items'=>[['id'=>'profile-1','slug'=>'<script>bad</script>','display_name'=>'<img src=x onerror=alert(1)>','destination_name'=>'London','adult_eligibility_status'=>'verified','adult_verified_at'=>'2026-11-25 10:00:00','publication_consent_version'=>'consent-v1','created_at'=>'2026-11-25 09:00:00']],
]);
$assert(str_contains($adminCompanionMarkup, '/admin/companions/profile-1/publish'), 'admin companion review exposes the audited publication action');
$assert(!str_contains($adminCompanionMarkup, '<img src=x onerror=alert(1)>') && !str_contains($adminCompanionMarkup, '<script>bad</script>'), 'admin companion review escapes profile data');

$publicHomeMarkup = $authViews->render('public/home', [
    'brand'=>['brand_name'=>'Maison Example','tagline'=>'A considered experience','seo_defaults'=>['title_suffix'=>' | Official','robots'=>'noindex,nofollow']],
    'sections'=>[],
]);
$assert(str_contains($publicHomeMarkup, '<title>Maison Example | Official</title>'), 'public homepage applies the configured SEO title suffix');
$assert(str_contains($publicHomeMarkup, '<meta name="robots" content="noindex,nofollow">'), 'public homepage applies the configured robots policy');
$assert(str_contains($publicHomeMarkup, 'href="/destinations"'), 'public homepage exposes destination discovery by default');
$publicPageMarkup = $authViews->render('public/page', [
    'brand'=>['brand_name'=>'Maison Example'],
    'title'=>'Privacy Notice',
    'content'=>'Public page content',
    'metaTitle'=>'Privacy Notice | Official',
    'metaDescription'=>'A clear description',
    'robots'=>'noindex,nofollow',
]);
$assert(str_contains($publicPageMarkup, '<title>Privacy Notice | Official · Maison Example</title>'), 'public CMS page renders the versioned SEO title');
$assert(str_contains($publicPageMarkup, '<meta name="robots" content="noindex,nofollow">'), 'public CMS page applies the configured robots policy');
$navigationMarkup = $authViews->render('admin/navigation', [
    'brandName'=>'Maison Example',
    'csrf'=>'navigation-csrf',
    'error'=>'',
    'saved'=>false,
    'items'=>[['id'=>'11111111-1111-4111-8111-111111111111','location_key'=>'header','label'=>'<script>bad</script>','target_type'=>'route','target_value'=>'/about','locale'=>'en','sort_order'=>10,'enabled'=>1]],
]);
$assert(str_contains($navigationMarkup, 'action="/admin/navigation"'), 'navigation workspace exposes item creation');
$assert(str_contains($navigationMarkup, 'action="/admin/navigation/11111111-1111-4111-8111-111111111111"'), 'navigation workspace exposes item update');
$assert(!str_contains($navigationMarkup, '<script>bad</script>'), 'navigation labels are escaped before rendering');
$publicNavMarkup = $authViews->render('public/home', [
    'brand'=>['brand_name'=>'Maison Example','tagline'=>'A considered experience','seo_defaults'=>['title_suffix'=>'','robots'=>'index,follow']],
    'sections'=>[],
    'navigation'=>[['label'=>'About','target_type'=>'page','target_value'=>'/pages/about']],
    'footerNavigation'=>[['label'=>'Contact','target_type'=>'url','target_value'=>'https://example.test/contact']],
]);
$assert(str_contains($publicNavMarkup, 'href="/pages/about"'), 'public homepage renders enabled navigation targets');
$assert(str_contains($publicNavMarkup, 'href="https://example.test/contact"'), 'public homepage renders footer navigation targets');
$contactMarkup = $authViews->render('public/contact', [
    'brandName'=>'Maison Example',
    'csrf'=>'contact-csrf',
    'error'=>'',
    'sent'=>false,
    'privacyAvailable'=>true,
    'values'=>['name'=>'<script>alert(1)</script>','email'=>'visitor@example.test'],
]);
$assert(str_contains($contactMarkup, 'action="/enquiries"'), 'public contact form posts to the enquiry endpoint');
$assert(str_contains($contactMarkup, 'name="privacy_acknowledged"'), 'public contact form requires privacy acknowledgement');
$assert(str_contains($contactMarkup, 'name="website"'), 'public contact form includes an unobtrusive spam honeypot');
$assert(!str_contains($contactMarkup, '<script>alert(1)</script>'), 'public contact values are escaped against reflected XSS');
$blockedContactMarkup = $authViews->render('public/contact', [
    'brandName'=>'Maison Example',
    'csrf'=>'contact-csrf',
    'error'=>'',
    'sent'=>false,
    'privacyAvailable'=>false,
    'values'=>[],
]);
$assert(!str_contains($blockedContactMarkup, 'action="/enquiries"'), 'public enquiries are disabled until a privacy notice is published');
$enquiryAdminMarkup = $authViews->render('admin/enquiries', [
    'brandName'=>'Maison Example',
    'csrf'=>'admin-enquiry-csrf',
    'error'=>'',
    'saved'=>false,
    'items'=>[['reference'=>'TV-0123456789','name'=>'<img src=x onerror=alert(1)>','email'=>'person@example.test','subject'=>'Hello','message'=>'Please contact me.','status'=>'new','created_at'=>'2026-11-25 10:00:00']],
]);
$assert(str_contains($enquiryAdminMarkup, '/admin/enquiries/TV-0123456789'), 'admin enquiry workspace exposes the status update action');
$assert(!str_contains($enquiryAdminMarkup, '<img src=x onerror=alert(1)>'), 'admin enquiry data is escaped before rendering');


$assert(str_contains($contentEditMarkup, 'Public readers still see version 1'), 'CMS editor distinguishes a live snapshot from newer draft content');
$assert(str_contains($contentEditMarkup, 'Publish version 2'), 'CMS editor offers explicit publication for newer draft versions');
$publishedMarkup = $authViews->render('admin/content', [
    'mode'=>'edit',
    'csrf'=>'cms-published-csrf',
    'error'=>'',
    'pages'=>[],
    'page'=>['id'=>'page-2','slug'=>'about','title'=>'About','locale'=>'en','content'=>'Published copy','meta_title'=>'','meta_description'=>'','status'=>'published','version'=>2,'published_version'=>2],
]);
$assert(!str_contains($publishedMarkup, '/publish'), 'CMS editor does not offer redundant publication when the current version is already live');



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
$triggerRestriction = new PDOException('Sensitive database error message');
$triggerRestriction->errorInfo = ['HY000',1419,'Do not expose this server diagnostic'];
$assert(MigrationRunner::isSharedHostingTriggerRestriction($triggerRestriction,'CREATE TRIGGER audit_guard BEFORE UPDATE ON audit_events FOR EACH ROW SIGNAL SQLSTATE \'45000\''),
    'migration runner recognizes only MySQL 1419 restrictions on trigger creation as a shared-hosting capability gap');
$assert(!MigrationRunner::isSharedHostingTriggerRestriction($triggerRestriction,'UPDATE audit_events SET event_key = \'changed\''),
    'migration runner never suppresses ordinary database failures as optional trigger capability');

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
$assert(Authorization::allows($superAdmin, 'site.content.manage'), 'Super Admin can manage deployment content');
$owner = new Principal('owner-1', ['owner'], true);
$admin = new Principal('admin-1', ['admin'], true);
$assert(Authorization::allows($owner, 'site.content.manage'), 'Owner can manage CMS pages');
$assert(Authorization::allows($admin, 'site.content.manage'), 'Admin can manage CMS pages');
$assert(!Authorization::allows($finance, 'site.content.manage'), 'Finance cannot manage CMS pages');
$assert(!Authorization::allows(new Principal('admin-2', ['admin'], false), 'site.content.manage'), 'CMS writes require administrator MFA');

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
$assert(isset($preflight['checks']['uploads_enabled'],$preflight['checks']['upload_size'],$preflight['checks']['post_size']) &&
    isset($preflight['checks']['memory_limit']),
    'installer preflight reports file upload, request-body and image-memory requirements');
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
$assert(Authorization::allows(new Principal('audit-superadmin',['super_admin'],true),'audit.events.view'), 'MFA-verified Super Admin can view audit events');
$assert(!Authorization::allows(new Principal('audit-owner',['owner'],true),'audit.events.view'), 'agency owner cannot view the platform security audit trail');
$assert(!Authorization::allows(new Principal('audit-superadmin-no-mfa',['super_admin'],false),'audit.events.view'), 'Super Admin without verified MFA cannot view audit events');
$auditMarkup = $authViews->render('admin/audit', [
    'brandName'=>'Maison Example',
    'items'=>[[
        'occurred_at'=>'2026-11-25 12:00:00',
        'event_key'=>'<script>alert(1)</script>',
        'actor_user_id'=>'actor-1',
        'target_type'=>'profile',
        'target_id'=>'profile-1',
        'reason'=>'Reviewed safely',
        'request_id'=>'request-1',
        'policy_version'=>'v1',
        'before_json'=>'must never be rendered',
        'after_json'=>'must never be rendered',
    ]],
    'filters'=>[],
    'page'=>1,
    'previousUrl'=>null,
    'nextUrl'=>'/admin/audit?page=2',
    'error'=>'',
]);
$assert(str_contains($auditMarkup, 'Security audit trail'), 'Super Admin audit workspace renders its title');
$assert(str_contains($auditMarkup, 'Maison Example'), 'audit workspace uses the active white-label identity');
$assert(str_contains($auditMarkup, '&lt;script&gt;alert(1)&lt;/script&gt;'), 'audit event labels are HTML-escaped');
$assert(!str_contains($auditMarkup, '<script>alert(1)</script>'), 'audit event data cannot inject executable markup');
$assert(!str_contains($auditMarkup, 'must never be rendered'), 'audit workspace does not render sensitive before/after snapshots');
$assert(str_contains($auditMarkup, '/admin/audit?page=2'), 'audit workspace exposes bounded pagination');
$publicProfileMarkup = $authViews->render('public/discovery', [
    'mode'=>'profile',
    'profile'=>[
        'slug'=>'verified-profile',
        'display_name'=>'Verified Profile',
        'destination_slug'=>'london',
        'destination_name'=>'London',
        'experience_category'=>'Representation',
        'biography'=>'Public profile biography',
        'languages'=>['en'],
    ],
    'canSaveFavorite'=>true,
    'csrf'=>'csrf-test-token',
]);
$assert(str_contains($publicProfileMarkup, 'action="/portal/favorites/verified-profile"'), 'authenticated client profile page exposes a private shortlist action');
$assert(str_contains($publicProfileMarkup, 'csrf-test-token'), 'shortlist action carries the server-issued CSRF token');
$anonymousProfileMarkup = $authViews->render('public/discovery', [
    'mode'=>'profile',
    'profile'=>['slug'=>'verified-profile','display_name'=>'Verified Profile','destination_slug'=>'london','destination_name'=>'London'],
    'canSaveFavorite'=>false,
    'csrf'=>'',
]);
$assert(str_contains($anonymousProfileMarkup, 'Client sign-in to save'), 'anonymous visitors are directed to client sign-in instead of receiving a mutation token');
\Talyvane\Infrastructure\Logging\PrivateErrorLogger::record(new RuntimeException('DB_PASSWORD=do-not-log-secret'),$privatePaths['logs'],$privatePaths['document_root'],'a1b2c3d4e5f60718');
$errorLogPath = $privatePaths['logs'] . DIRECTORY_SEPARATOR . 'application-error.log';
$errorLogContents = is_file($errorLogPath) ? (string) file_get_contents($errorLogPath) : '';
$assert($errorLogContents !== '' && str_contains($errorLogContents,'RuntimeException') && str_contains($errorLogContents,'a1b2c3d4e5f60718'), 'private runtime error log records exception class and correlation reference');
$pdoDiagnostic = new PDOException('Sensitive SQL message must not be logged');
$pdoDiagnostic->errorInfo = ['HY000',1142,'Access denied for this operation'];
\Talyvane\Infrastructure\Logging\PrivateErrorLogger::record($pdoDiagnostic,$privatePaths['logs'],$privatePaths['document_root'],'a1b2c3d4e5f60719');
$errorLogContents = (string) file_get_contents($errorLogPath);
$assert(str_contains($errorLogContents,'"sql_state":"HY000"') && str_contains($errorLogContents,'"driver_code":1142'),
    'private runtime error log records safe PDO SQLSTATE and native driver code');
$assert(!str_contains($errorLogContents,'Sensitive SQL message') && !str_contains($errorLogContents,'Access denied'),
    'private runtime error log excludes PDO exception messages');
$assert(!str_contains($errorLogContents,'DB_PASSWORD') && !str_contains($errorLogContents,'do-not-log-secret'), 'private runtime error log never records exception messages or secrets');
$errorLogMode = is_file($errorLogPath) ? (fileperms($errorLogPath) & 0777) : 0;
$assert($errorLogMode === 0600, 'private runtime error log is restricted to owner read/write permissions');
\Talyvane\Infrastructure\Logging\PrivateErrorLogger::record(new RuntimeException('must not create a public log'),$privatePaths['document_root'],$privatePaths['document_root'],'1122334455667788');
$assert(!is_file($privatePaths['document_root'] . DIRECTORY_SEPARATOR . 'application-error.log'), 'private error logger refuses to write inside the public document root');
$destinationMarkup = $authViews->render('admin/destinations',[
    'brandName'=>'Maison Example',
    'items'=>[[
        'id'=>'destination-1','slug'=>'london','country_name'=>'United Kingdom','country_code'=>'GB',
        'display_name'=>'<script>alert(1)</script>','city_name'=>'London','currency_code'=>'GBP',
        'locale'=>'en-GB','time_zone'=>'Europe/London','status'=>'draft','market_decision'=>'pending',
    ]],
    'countries'=>[['alpha2'=>'GB','name'=>'United Kingdom','enabled'=>1]],
    'currencies'=>[['code'=>'GBP','name'=>'Pound sterling','enabled'=>1]],
    'error'=>'','notice'=>'','csrf'=>'csrf-destination-token',
]);
$assert(str_contains($destinationMarkup,'&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($destinationMarkup,'<script>alert(1)</script>'), 'destination admin escapes database content before rendering');
$assert(str_contains($destinationMarkup,'/admin/destinations/destination-1') && str_contains($destinationMarkup,'csrf-destination-token'), 'destination admin renders owner-scoped edit forms with CSRF protection');
$assert(str_contains($destinationMarkup,'/admin/destinations/destination-1/cover') &&
    str_contains($destinationMarkup,'name="rights_confirmed"') &&
    str_contains($destinationMarkup,'Submit cover for independent review'),
    'destination admin exposes a CSRF-protected rights-attested cover upload form');
$pendingDestinationMarkup=$authViews->render('admin/destinations',[
    'brandName'=>'Maison Example',
    'items'=>[],
    'countries'=>[],
    'currencies'=>[],
    'pendingCovers'=>[['id'=>'cover-1','destination_name'=>'London','created_at'=>'2026-11-25 12:00:00']],
    'error'=>'','notice'=>'','csrf'=>'cover-review-csrf',
]);
$assert(str_contains($pendingDestinationMarkup,'/admin/destination-covers/cover-1/review') &&
    str_contains($pendingDestinationMarkup,'name="decision"') &&
    str_contains($pendingDestinationMarkup,'name="reason"'),
    'destination cover review requires a reasoned independent approve/reject decision');
$assert(DestinationCoverService::RIGHTS_VERSION==='destination-cover-rights-v1' &&
    DestinationCoverService::RIGHTS_STATEMENT==='I confirm that I own or have the necessary rights and permissions to use this image as the destination cover, including all copyright, licence, property and identifiable-person likeness rights. I authorize its display on this destination page and understand that approval is required before publication.',
    'destination cover rights declaration preserves its canonical versioned statement exactly');
$assert(PrivateMediaStorage::validKey('destination-covers/0123456789abcdef0123456789abcdef.webp'),
    'destination cover storage accepts only opaque sanitized private media keys');
$marketMarkup = $authViews->render('admin/markets',[
    'brandName'=>'Maison Example',
    'items'=>[[
        'alpha2'=>'GB','alpha3'=>'GBR','name'=>'<script>alert(1)</script>',
        'country_enabled'=>1,'market_enabled'=>0,'decision'=>'pending',
    ]],
    'error'=>'','notice'=>'','csrf'=>'csrf-market-token',
]);
$assert(str_contains($marketMarkup,'&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($marketMarkup,'<script>alert(1)</script>'), 'market compliance workspace escapes country reference data');
$assert(str_contains($marketMarkup,'/admin/markets/GB/review') && str_contains($marketMarkup,'csrf-market-token'), 'market review form is country-scoped and CSRF protected');

$companionMediaMarkup = $authViews->render('portal/media',[
    'brandName'=>'Talyvane','csrf'=>'media-csrf','error'=>'','notice'=>'',
    'profile'=>['id'=>'profile-1','slug'=>'talent','display_name'=>'<script>profile</script>','status'=>'published','destination_name'=>'London'],
    'items'=>[['id'=>'media-1','width'=>320,'height'=>320,'created_at'=>'2026-11-25','review_status'=>'pending','media_scope'=>'private']],
]);
$assert(str_contains($companionMediaMarkup,'enctype="multipart/form-data"') &&
    str_contains($companionMediaMarkup,'name="rights_confirmed"') &&
    str_contains($companionMediaMarkup,'name="depicted_adults_confirmed"') &&
    str_contains($companionMediaMarkup,'name="publication_confirmed"') &&
    str_contains($companionMediaMarkup,'/portal/companion/publication/withdraw') &&
    str_contains($companionMediaMarkup,'&lt;script&gt;profile&lt;/script&gt;'),
    'companion media workspace requires separate rights/publication attestations and escapes profile data');
$unpublishedMediaMarkup = $authViews->render('portal/media',[
    'brandName'=>'Talyvane','csrf'=>'media-csrf','error'=>'','notice'=>'',
    'profile'=>['id'=>'profile-1','slug'=>'talent','display_name'=>'Talent Example','status'=>'unpublished','destination_name'=>'London'],
    'items'=>[],
]);
$assert(str_contains($unpublishedMediaMarkup,'/portal/companion/publication/renew') &&
    str_contains($unpublishedMediaMarkup,'name="publication_consent_confirmed"'),
    'unpublished companion can grant a new versioned publication consent only through explicit confirmation');
$adminClientsMarkup = $authViews->render('admin/clients',[
    'brandName'=>'Maison Example','csrf'=>'admin-clients-csrf','error'=>'',
    'notice'=>'Membership suspension saved.',
    'clients'=>[[
        'id'=>'client-1','email'=>'client@example.test','email_verified_at'=>'2026-11-25 10:00:00',
        'membership_id'=>'membership-1','plan_key'=>'gold','tier_name'=>'Gold','membership_status'=>'active',
    ]],
    'entitlements'=>['media.private_gallery'=>'Private members-only gallery'],
]);
$assert(str_contains($adminClientsMarkup,'/admin/memberships/membership-1/suspend') &&
    str_contains($adminClientsMarkup,'/admin/memberships/membership-1/cancel') &&
    str_contains($adminClientsMarkup,'Membership suspension saved.'),
    'MFA-admin membership workspace exposes suspend/cancel actions and outcome feedback');
$adminSuspendedMembershipMarkup = $authViews->render('admin/clients',[
    'brandName'=>'Maison Example','csrf'=>'admin-clients-csrf','error'=>'','notice'=>'',
    'clients'=>[[
        'id'=>'client-1','email'=>'client@example.test','email_verified_at'=>'2026-11-25 10:00:00',
        'membership_id'=>'membership-1','plan_key'=>'gold','tier_name'=>'Gold','membership_status'=>'suspended',
    ]],
    'entitlements'=>['media.private_gallery'=>'Private members-only gallery'],
]);
$assert(str_contains($adminSuspendedMembershipMarkup,'suspended') &&
    !str_contains($adminSuspendedMembershipMarkup,'/admin/memberships/membership-1/suspend') &&
    !str_contains($adminSuspendedMembershipMarkup,'/admin/memberships/membership-1/cancel'),
    'admin client directory retains suspended membership status without offering active-only transitions');
$adminMediaMarkup = $authViews->render('admin/profile-media',[
    'brandName'=>'Talyvane','csrf'=>'review-csrf','error'=>'','notice'=>'',
    'items'=>[['id'=>'media-1','profile_name'=>'Talent Example','profile_slug'=>'talent','destination_name'=>'London','width'=>320,'height'=>320,'created_at'=>'2026-11-25']],
]);
$assert(str_contains($adminMediaMarkup,'/admin/profile-media/media-1/preview') &&
    str_contains($adminMediaMarkup,'name="decision"') && str_contains($adminMediaMarkup,'name="adult_safety_confirmed"') &&
    str_contains($adminMediaMarkup,'name="rights_confirmed"') && str_contains($adminMediaMarkup,'name="consent_confirmed"') && str_contains($adminMediaMarkup,'name="reason"'),
    'administrator media review workspace uses private preview and reasoned approve/reject controls');
$destinationDiscoveryMarkup=$authViews->render('public/discovery',[
    'mode'=>'destinations','brandName'=>'Talyvane',
    'destinations'=>[['slug'=>'london','display_name'=>'London','country_code'=>'GB','time_zone'=>'Europe/London','cover_url'=>'/destination-covers/london']],
]);
$assert(str_contains($destinationDiscoveryMarkup,'src="/destination-covers/london"') &&
    str_contains($destinationDiscoveryMarkup,'London destination cover'),
    'public destination discovery renders only the approved opaque destination cover route');
$destinationDetailMarkup=$authViews->render('public/discovery',[
    'mode'=>'destination','brandName'=>'Talyvane',
    'destination'=>['slug'=>'london','display_name'=>'London','cover_url'=>'/destination-covers/london'],
    'profiles'=>[],
]);
$assert(str_contains($destinationDetailMarkup,'src="/destination-covers/london"'),
    'public destination detail renders its approved cover image');
$publicMediaMarkup = $authViews->render('public/discovery',[
    'mode'=>'profile','brandName'=>'Talyvane','canSaveFavorite'=>false,'csrf'=>'',
    'profile'=>['slug'=>'talent','display_name'=>'Talent Example','destination_slug'=>'london','destination_name'=>'London',
        'experience_category'=>'Profile','biography'=>'','languages'=>[],'media'=>[['id'=>'media-1','url'=>'/profile-media/media-1']]],
]);
$assert(str_contains($publicMediaMarkup,'src="/profile-media/media-1"') &&
    str_contains($publicMediaMarkup,'Approved profile photograph'),
    'public profile template renders approved media only through opaque controller routes');

$logRoot = $tempRoot . DIRECTORY_SEPARATOR . 'private-logs';
$logPublic = $tempRoot . DIRECTORY_SEPARATOR . 'public-root';
mkdir($logRoot,0700,true);
mkdir($logPublic,0755,true);
$logFile = $logRoot . DIRECTORY_SEPARATOR . 'application-error.log';
file_put_contents($logFile,str_repeat('x',5_242_880));
chmod($logFile,0600);
PrivateErrorLogger::record(new RuntimeException('sensitive exception message must never be logged'),$logRoot,$logPublic,'0123456789abcdef');
$rotatedLog = $logFile . '.1';
$currentLog = (string) file_get_contents($logFile);
$assert(is_file($rotatedLog) && strlen($currentLog) < 1024 && !str_contains($currentLog,'sensitive exception message') &&
    (fileperms($logFile) & 0777) === 0600,
    'private error logging rotates at five MiB, keeps owner-only permissions and excludes exception messages');
$assert(is_file($logRoot . DIRECTORY_SEPARATOR . '.application-error.lock') &&
    (fileperms($logRoot . DIRECTORY_SEPARATOR . '.application-error.lock') & 0777) === 0600,
    'private error logging serializes concurrent writers with a private stable lock');

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tempRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($iterator as $entry) {
    $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
}
rmdir($tempRoot);

fwrite(STDOUT, sprintf("Native PHP foundation: %d assertions passed.\n", $tests));
