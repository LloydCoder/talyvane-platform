<?php

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminLoginController;
use App\Http\Controllers\Admin\AdminMfaController;
use App\Http\Controllers\Admin\AdminFirstLoginPasswordController;
use App\Http\Controllers\Admin\AdminInitialPasswordRecoveryController;
use App\Http\Controllers\Install\InstallerController;
use App\Http\Controllers\Install\InstallSuccessController;
use App\Http\Middleware\NoStoreSensitiveResponses;
use App\Http\Controllers\Admin\BookingAdminController;
use App\Http\Controllers\Admin\ClientManagementController;
use App\Http\Controllers\Admin\BookingSettingsController;
use App\Http\Controllers\Admin\BrandingController;
use App\Http\Controllers\Admin\CompanionProfileController;
use App\Http\Controllers\Admin\DestinationController;
use App\Http\Controllers\Admin\EnquiryAdminController;
use App\Http\Controllers\Admin\GlobalizationController;
use App\Http\Controllers\Admin\PaymentMethodController;
use App\Http\Controllers\Admin\PaymentSettingsController;
use App\Http\Controllers\Admin\SitePageController;
use App\Http\Controllers\Admin\PlatformFeatureController;
use App\Http\Controllers\Admin\ModelAgencyApplicationAdminController;
use App\Http\Controllers\Admin\ModelTalentRepresentationAgreementController;
use App\Http\Controllers\Admin\ModelTalentPortfolioAssetController;
use App\Http\Controllers\Admin\ModelTalentImageLicenceController;
use App\Http\Controllers\Admin\ModelCastingAdminController;
use App\Http\Controllers\Admin\ModelTalentAssignmentAdminController;
use App\Http\Controllers\Admin\ModelTalentSettlementAdminController;
use App\Http\Controllers\Admin\SafeguardingCaseController;
use App\Http\Controllers\Admin\SafeguardingGovernanceController;
use App\Http\Controllers\Admin\SecretManagementController;
use App\Http\Controllers\ModelAgencyApplicationController;
use App\Http\Controllers\ModelAgencyApplicationRightsController;
use App\Http\Controllers\AccountSecurityController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\ClientFavoriteController;
use App\Http\Controllers\CompanionBookingController;
use App\Http\Controllers\CompanionMediaController;
use App\Http\Controllers\ProfileMediaController;
use App\Http\Controllers\Admin\ProfileMediaReviewController;
use App\Http\Controllers\CompanionPortalController;
use App\Http\Controllers\DiscoveryController;
use App\Http\Controllers\EnquiryController;
use App\Http\Controllers\PortalAuthController;
use App\Http\Controllers\PortalDashboardController;
use App\Http\Controllers\PageController;
use App\Models\SitePage;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Support\Facades\Route;

Route::middleware(NoStoreSensitiveResponses::class)->prefix('install')->name('install.')->group(function (): void {
    Route::get('/', [InstallerController::class, 'index'])->name('index');
    Route::post('/', [InstallerController::class, 'store'])->middleware('throttle:5,1')->name('store');
    Route::get('/verify', [InstallerController::class, 'verifyForm'])->name('verify');
    Route::post('/verify', [InstallerController::class, 'verify'])->middleware('throttle:5,1')->name('verify.submit');
    Route::get('/success', [InstallSuccessController::class, 'show'])->name('success');
});

Route::get('/', function (\App\Services\BrandSettings $settings) {
    return view('home', [
        'brand' => $settings->publicBrand(),
        'pages' => SitePage::query()->where('is_published', true)->orderBy('title')->get(['title', 'slug']),
    ]);
})->middleware('feature:public.discovery')->name('home');

Route::get('/destinations', [DiscoveryController::class, 'destinations'])->middleware('feature:public.discovery')->name('destinations.index');
Route::get('/destinations/{slug}', [DiscoveryController::class, 'destination'])->middleware('feature:public.discovery')->name('destinations.show');
Route::get('/companions', [DiscoveryController::class, 'companions'])->middleware('feature:public.discovery')->name('companions.index');
Route::get('/companions/{slug}', [DiscoveryController::class, 'companion'])->middleware('feature:public.discovery')->name('companions.show');
Route::get('/profile-media/{media}', [ProfileMediaController::class, 'show'])->middleware('feature:public.discovery')->name('profile-media.show');
Route::get('/contact', [EnquiryController::class, 'create'])->name('contact');
Route::get('/pages/{slug}', [PageController::class, 'show'])->middleware('feature:cms.pages')->name('pages.show');
Route::view('/privacy', 'legal.privacy')->name('privacy');
Route::view('/terms', 'legal.terms')->name('terms');
Route::post('/enquiries', [EnquiryController::class, 'store'])->middleware('throttle:5,1')->name('enquiries.store');
Route::get('/models/apply', [ModelAgencyApplicationController::class, 'create'])->middleware('feature:model-agency.applications')->name('model-agency.apply');
Route::post('/models/apply', [ModelAgencyApplicationController::class, 'store'])->middleware(['feature:model-agency.applications', 'throttle:5,1'])->name('model-agency.apply.store');
Route::get('/models/apply/received', [ModelAgencyApplicationController::class, 'received'])->name('model-agency.apply.received');
Route::get('/models/application-support', [ModelAgencyApplicationRightsController::class, 'create'])->name('model-agency.application-support');
Route::post('/models/application-support', [ModelAgencyApplicationRightsController::class, 'submitRequest'])->middleware('throttle:3,1')->name('model-agency.application-support.submit');
Route::get('/models/application-support/verify', [ModelAgencyApplicationRightsController::class, 'verifyForm'])->name('model-agency.application-support.verify');
Route::post('/models/application-support/verify', [ModelAgencyApplicationRightsController::class, 'verify'])->middleware('throttle:10,1')->name('model-agency.application-support.verify.submit');
Route::get('/models/application-support/{rightsRequest}', [ModelAgencyApplicationRightsController::class, 'show'])->name('model-agency.application-support.show');
Route::post('/models/application-support/{rightsRequest}/finish', [ModelAgencyApplicationRightsController::class, 'finish'])->name('model-agency.application-support.finish');
Route::patch('/models/application-support/{rightsRequest}', [ModelAgencyApplicationRightsController::class, 'correct'])->name('model-agency.application-support.correct');
Route::post('/models/application-support/{rightsRequest}/withdraw', [ModelAgencyApplicationRightsController::class, 'withdraw'])->name('model-agency.application-support.withdraw');
Route::post('/models/application-support/{rightsRequest}/erase', [ModelAgencyApplicationRightsController::class, 'erase'])->name('model-agency.application-support.erase');

Route::middleware('guest')->group(function (): void {
    Route::get('/register', [PortalAuthController::class, 'registerForm'])->name('register');
    Route::post('/register', [PortalAuthController::class, 'register'])->middleware('throttle:5,1')->name('register.store');
    Route::get('/login', [PortalAuthController::class, 'loginForm'])->name('login');
    Route::post('/login', [PortalAuthController::class, 'login'])->middleware('throttle:10,1')->name('login.store');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [PortalAuthController::class, 'logout'])->name('logout');
    Route::patch('/account/password', [AccountSecurityController::class, 'updatePassword'])->middleware('admin.session-current')->name('account.password.update');
    Route::get('/email/verify', [PortalAuthController::class, 'verificationNotice'])->name('verification.notice');
    Route::get('/email/verify/{id}/{hash}', function (EmailVerificationRequest $request) {
        $request->fulfill();

        return redirect()->route($request->user()->role === 'companion' ? 'companion-portal.dashboard' : 'portal.dashboard');
    })->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
    Route::post('/email/verification-notification', [PortalAuthController::class, 'resendVerification'])->middleware('throttle:6,1')->name('verification.send');

    Route::middleware(['verified', 'role:client', 'feature:portals.client'])->group(function (): void {
        Route::get('/portal', [PortalDashboardController::class, 'client'])->name('portal.dashboard');
        Route::get('/companions/{slug}/book', [BookingController::class, 'create'])->middleware('feature:bookings.companionship')->name('bookings.create');
        Route::post('/bookings', [BookingController::class, 'store'])->middleware('feature:bookings.companionship')->name('bookings.store');
        Route::post('/client/favorites/{profile}', [ClientFavoriteController::class, 'store'])->middleware('throttle:30,1')->name('client.favorites.store');
        Route::delete('/client/favorites/{profile}', [ClientFavoriteController::class, 'destroy'])->name('client.favorites.destroy');
        Route::post('/bookings/{booking}/cancel', [BookingController::class, 'cancel'])->withoutMiddleware(['verified', 'feature:portals.client'])->name('bookings.cancel');
    });

    // Privacy-critical companion controls remain reachable even when the companion portal module is disabled.
    Route::middleware(['role:companion'])->group(function (): void {
        Route::get('/companion-portal/privacy', [CompanionPortalController::class, 'privacyStatus'])->name('companion-portal.privacy-status');
        Route::delete('/companion-portal/profiles/{profile}/media/{media}', [CompanionMediaController::class, 'destroy'])->name('companion-portal.profiles.media.destroy');
        Route::patch('/companion-portal/profiles/{profile}/media/{media}/withdraw-members-only-consent', [CompanionMediaController::class, 'withdrawMembersOnlyConsent'])->name('companion-portal.profiles.media.withdraw-members-only-consent');
        Route::post('/companion-portal/profiles/{profile}/withdraw-consent', [CompanionPortalController::class, 'withdrawPublicationConsent'])->name('companion-portal.profiles.withdraw-consent');
    });

    Route::middleware(['verified', 'role:companion', 'feature:portals.companion'])->group(function (): void {
        Route::get('/companion-portal', [CompanionPortalController::class, 'index'])->name('companion-portal.dashboard');
        Route::post('/companion-portal/profiles/{profile}/media', [CompanionMediaController::class, 'store'])->middleware('throttle:10,1')->name('companion-portal.profiles.media.store');
        Route::post('/companion-portal/profiles/{profile}/automatic-booking', [CompanionPortalController::class, 'updateAutomaticBooking'])->withoutMiddleware(['verified', 'feature:portals.companion'])->name('companion-portal.profiles.automatic-booking');
        Route::post('/companion-portal/profiles/{profile}/availability', [CompanionPortalController::class, 'storeAvailability'])->name('companion-portal.profiles.availability.store');
        Route::delete('/companion-portal/profiles/{profile}/availability/{rule}', [CompanionPortalController::class, 'destroyAvailability'])->name('companion-portal.profiles.availability.destroy');
        Route::patch('/companion-portal/bookings/{booking}', [CompanionBookingController::class, 'update'])->name('companion-portal.bookings.update');
    });
});

Route::prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/first-login/password', [AdminFirstLoginPasswordController::class, 'create'])->name('first-login.password');
    Route::post('/first-login/password', [AdminFirstLoginPasswordController::class, 'update'])->middleware('throttle:5,1')->name('first-login.password.update');
    Route::get('/first-login/recover', [AdminInitialPasswordRecoveryController::class, 'create'])->name('first-login.recover');
    Route::post('/first-login/recover', [AdminInitialPasswordRecoveryController::class, 'send'])->middleware('throttle:5,1')->name('first-login.recover.send');
    Route::get('/first-login/recover/verify', [AdminInitialPasswordRecoveryController::class, 'verifyForm'])->name('first-login.recover.verify');
    Route::post('/first-login/recover/verify', [AdminInitialPasswordRecoveryController::class, 'complete'])->middleware('throttle:5,1')->name('first-login.recover.complete');

    Route::middleware('guest')->group(function (): void {
        Route::get('/login', [AdminLoginController::class, 'create'])->name('login');
        Route::post('/login', [AdminLoginController::class, 'store'])->middleware('throttle:10,1')->name('login.store');
    });

    Route::get('/mfa/challenge', [AdminMfaController::class, 'challenge'])->name('mfa.challenge');
    Route::post('/mfa/challenge', [AdminMfaController::class, 'verifyChallenge'])->middleware('throttle:5,1')->name('mfa.challenge.verify');
    Route::post('/mfa/recovery', [AdminMfaController::class, 'verifyRecovery'])->middleware('throttle:5,1')->name('mfa.recovery.verify');

    Route::middleware(['auth', 'role:super_admin,owner,admin,finance,verifier,safeguarding', 'admin.session-current'])->group(function (): void {
        Route::get('/mfa/setup', [AdminMfaController::class, 'setup'])->middleware(NoStoreSensitiveResponses::class)->name('mfa.setup');
        Route::post('/mfa/setup', [AdminMfaController::class, 'confirmSetup'])->middleware('throttle:5,1')->name('mfa.setup.confirm');
        Route::get('/mfa/recovery-codes', [AdminMfaController::class, 'recoveryCodes'])->middleware(NoStoreSensitiveResponses::class)->name('mfa.recovery-codes');
    });

    // Eligibility verifiers receive a dedicated, purpose-limited queue only. They cannot enter agency or finance workspaces.
    Route::middleware(['auth', 'role:verifier', 'admin.session-current', 'admin.mfa'])->prefix('adult-eligibility')->name('adult-eligibility.')->group(function (): void {
        Route::get('/', [ModelAgencyApplicationAdminController::class, 'verifierQueue'])->name('index');
        Route::post('/{application}/verify', [ModelAgencyApplicationAdminController::class, 'verifyAdultEligibility'])->middleware('throttle:5,1')->name('verify');
    });

    Route::middleware(['auth', 'role:super_admin', 'admin.session-current', 'admin.mfa'])->group(function (): void {
        Route::get('/features', [PlatformFeatureController::class, 'index'])->name('features.index');
        Route::patch('/features/{feature}', [PlatformFeatureController::class, 'update'])->middleware('throttle:5,1')->name('features.update');
    });

    // Deployment secrets are isolated to the canonical Super Admin and require active MFA.
    Route::middleware(['auth', 'role:super_admin', 'admin.session-current', 'admin.mfa'])->group(function (): void {
        Route::get('/secrets', [SecretManagementController::class, 'index'])->name('secrets.index');
        Route::post('/secrets/{secretKey}', [SecretManagementController::class, 'store'])
            ->middleware('throttle:5,1')
            ->name('secrets.store');
        Route::delete('/secrets/{secretKey}', [SecretManagementController::class, 'destroy'])
            ->middleware('throttle:5,1')
            ->name('secrets.destroy');
    });

    // Deployment-wide configuration is exclusively controlled by the Super Admin.
    Route::middleware(['auth', 'role:super_admin', 'admin.session-current', 'admin.mfa'])->group(function (): void {
        Route::get('/branding', [BrandingController::class, 'edit'])->name('branding.edit');
        Route::put('/branding', [BrandingController::class, 'update'])->name('branding.update');

        Route::get('/globalization', [GlobalizationController::class, 'edit'])->name('globalization');
        Route::put('/globalization/defaults', [GlobalizationController::class, 'updateDefaults'])->name('globalization.defaults.update');
        Route::post('/globalization/currencies', [GlobalizationController::class, 'storeCurrency'])->name('globalization.currencies.store');
        Route::patch('/globalization/currencies/{currency}/enabled', [GlobalizationController::class, 'toggleCurrency'])->name('globalization.currencies.enabled');
        Route::post('/globalization/markets', [GlobalizationController::class, 'storeMarket'])->name('globalization.markets.store');
        Route::post('/globalization/markets/{market}/review', [GlobalizationController::class, 'reviewMarket'])->name('globalization.markets.review');

        Route::resource('destinations', DestinationController::class)->except(['show', 'destroy']);
        Route::get('/booking-settings', [BookingSettingsController::class, 'edit'])->name('bookings.settings');
        Route::put('/booking-settings', [BookingSettingsController::class, 'update'])->name('bookings.settings.update');
        Route::resource('pages', SitePageController::class)->except(['show', 'destroy']);

        Route::get('/payment-settings', [PaymentSettingsController::class, 'edit'])->middleware('feature:payments.configuration')->name('payment-settings');
        Route::put('/payment-settings', [PaymentSettingsController::class, 'update'])->middleware('feature:payments.configuration')->name('payment-settings.update');
        Route::get('/payment-methods', [PaymentMethodController::class, 'index'])->middleware('feature:payments.configuration')->name('payment-methods');
        Route::post('/payment-methods', [PaymentMethodController::class, 'store'])->middleware('feature:payments.configuration')->name('payment-methods.store');
        Route::post('/payment-methods/{paymentMethod}/review', [PaymentMethodController::class, 'review'])->middleware('feature:payments.configuration')->name('payment-methods.review');
        Route::patch('/payment-methods/{paymentMethod}/enabled', [PaymentMethodController::class, 'toggle'])->middleware('feature:payments.configuration')->name('payment-methods.enabled');
    });

    // Agency owners and administrators retain day-to-day operations, not deployment-wide configuration.
    Route::middleware(['auth', 'role:super_admin,owner,admin', 'admin.session-current', 'admin.mfa'])->group(function (): void {
        Route::get('/', AdminDashboardController::class)->name('dashboard');
        Route::get('/profiles', [CompanionProfileController::class, 'index'])->name('profiles.index');
        Route::get('/profiles/create', [CompanionProfileController::class, 'create'])->name('profiles.create');
        Route::post('/profiles', [CompanionProfileController::class, 'store'])->name('profiles.store');
        Route::get('/profile-media/{media}/preview', [ProfileMediaController::class, 'preview'])->name('profile-media.preview');
        Route::post('/profile-media/{media}/review', [ProfileMediaReviewController::class, 'update'])->name('profile-media.review');
        Route::post('/profiles/{profile}/publish', [CompanionProfileController::class, 'publish'])->name('profiles.publish');
        Route::post('/profiles/{profile}/unpublish', [CompanionProfileController::class, 'unpublish'])->name('profiles.unpublish');
        Route::get('/enquiries', [EnquiryAdminController::class, 'index'])->name('enquiries.index');
        Route::get('/model-applications', [ModelAgencyApplicationAdminController::class, 'index'])->name('model-applications.index');
        Route::patch('/model-applications/{application}', [ModelAgencyApplicationAdminController::class, 'update'])->name('model-applications.update');
        Route::post('/model-applications/{application}/adult-eligibility', [ModelAgencyApplicationAdminController::class, 'verifyAdultEligibility'])->middleware('throttle:5,1')->name('model-applications.adult-eligibility');
        Route::post('/model-applications/{application}/legal-hold', [ModelAgencyApplicationAdminController::class, 'updateLegalHold'])->name('model-applications.legal-hold');
        Route::post('/model-applications/{application}/agreements', [ModelTalentRepresentationAgreementController::class, 'store'])->middleware(['feature:model-agency.usage-rights', 'throttle:10,1'])->name('model-applications.agreements.store');
        Route::post('/model-representation-agreements/{agreement}/offer', [ModelTalentRepresentationAgreementController::class, 'offer'])->middleware(['feature:model-agency.usage-rights', 'throttle:10,1'])->name('model-representation-agreements.offer');
        Route::post('/model-representation-agreements/{agreement}/execute', [ModelTalentRepresentationAgreementController::class, 'execute'])->middleware(['feature:model-agency.usage-rights', 'throttle:5,1'])->name('model-representation-agreements.execute');
        Route::post('/model-representation-agreements/{agreement}/terminate', [ModelTalentRepresentationAgreementController::class, 'terminate'])->middleware('throttle:5,1')->name('model-representation-agreements.terminate');

        Route::post('/model-applications/{application}/portfolio-assets', [ModelTalentPortfolioAssetController::class, 'store'])->middleware(['feature:model-agency.usage-rights', 'throttle:10,1'])->name('model-applications.portfolio-assets.store');
        Route::get('/model-portfolio-assets/{asset}/preview', [ModelTalentPortfolioAssetController::class, 'preview'])->middleware('feature:model-agency.usage-rights')->name('model-portfolio-assets.preview');
        Route::post('/model-portfolio-assets/{asset}/review', [ModelTalentPortfolioAssetController::class, 'review'])->middleware('throttle:10,1')->name('model-portfolio-assets.review');
        Route::post('/model-portfolio-assets/{asset}/withdraw-consent', [ModelTalentPortfolioAssetController::class, 'withdrawConsent'])->middleware('throttle:5,1')->name('model-portfolio-assets.withdraw-consent');

        Route::post('/model-portfolio-assets/{asset}/licences', [ModelTalentImageLicenceController::class, 'store'])->middleware(['feature:model-agency.usage-rights', 'throttle:10,1'])->name('model-portfolio-assets.licences.store');
        Route::post('/model-image-licences/{licence}/activate', [ModelTalentImageLicenceController::class, 'activate'])->middleware(['feature:model-agency.usage-rights', 'throttle:5,1'])->name('model-image-licences.activate');
        Route::post('/model-image-licences/{licence}/revoke', [ModelTalentImageLicenceController::class, 'revoke'])->middleware('throttle:5,1')->name('model-image-licences.revoke');

        Route::get('/model-castings', [ModelCastingAdminController::class, 'index'])->name('model-castings.index');
        Route::post('/model-castings', [ModelCastingAdminController::class, 'store'])->middleware(['feature:model-agency.casting', 'throttle:10,1'])->name('model-castings.store');
        Route::post('/model-castings/{call}/publish', [ModelCastingAdminController::class, 'publish'])->middleware(['feature:model-agency.casting', 'throttle:5,1'])->name('model-castings.publish');
        Route::post('/model-castings/{call}/close', [ModelCastingAdminController::class, 'close'])->middleware('throttle:5,1')->name('model-castings.close');
        Route::post('/model-castings/{call}/cancel', [ModelCastingAdminController::class, 'cancel'])->middleware('throttle:5,1')->name('model-castings.cancel');
        Route::post('/model-castings/{call}/submissions', [ModelCastingAdminController::class, 'submit'])->middleware(['feature:model-agency.casting', 'throttle:10,1'])->name('model-castings.submissions.store');
        Route::post('/model-casting-submissions/{submission}/decision', [ModelCastingAdminController::class, 'decide'])->middleware('throttle:10,1')->name('model-casting-submissions.decision');
        Route::post('/model-casting-submissions/{submission}/withdraw', [ModelCastingAdminController::class, 'withdraw'])->middleware('throttle:5,1')->name('model-casting-submissions.withdraw');
        Route::get('/model-assignments', [ModelTalentAssignmentAdminController::class, 'index'])->name('model-assignments.index');
        Route::post('/model-assignments', [ModelTalentAssignmentAdminController::class, 'store'])->middleware(['feature:model-agency.assignments', 'throttle:10,1'])->name('model-assignments.store');
        Route::post('/model-assignments/{assignment}/offer', [ModelTalentAssignmentAdminController::class, 'offer'])->middleware(['feature:model-agency.assignments', 'throttle:5,1'])->name('model-assignments.offer');
        Route::post('/model-assignments/{assignment}/accept', [ModelTalentAssignmentAdminController::class, 'accept'])->middleware('throttle:5,1')->name('model-assignments.accept');
        Route::post('/model-assignments/{assignment}/confirm', [ModelTalentAssignmentAdminController::class, 'confirm'])->middleware('throttle:5,1')->name('model-assignments.confirm');
        Route::post('/model-assignments/{assignment}/start', [ModelTalentAssignmentAdminController::class, 'start'])->middleware('throttle:5,1')->name('model-assignments.start');
        Route::post('/model-assignments/{assignment}/complete', [ModelTalentAssignmentAdminController::class, 'complete'])->middleware('throttle:5,1')->name('model-assignments.complete');
        Route::post('/model-assignments/{assignment}/decline', [ModelTalentAssignmentAdminController::class, 'decline'])->middleware('throttle:5,1')->name('model-assignments.decline');
        Route::post('/model-assignments/{assignment}/cancel', [ModelTalentAssignmentAdminController::class, 'cancel'])->middleware('throttle:5,1')->name('model-assignments.cancel');
        Route::post('/model-assignments/{assignment}/dispute', [ModelTalentAssignmentAdminController::class, 'dispute'])->middleware('throttle:5,1')->name('model-assignments.dispute');








        Route::patch('/enquiries/{enquiry}', [EnquiryAdminController::class, 'update'])->name('enquiries.update');
        Route::get('/clients', [ClientManagementController::class, 'index'])->name('clients.index');
        Route::post('/clients/{client}/membership', [ClientManagementController::class, 'assignMembership'])->middleware('feature:memberships')->name('clients.membership');
        Route::get('/bookings', [BookingAdminController::class, 'index'])->name('bookings.index');
        Route::patch('/bookings/{booking}', [BookingAdminController::class, 'update'])->name('bookings.update');
        Route::post('/bookings/{booking}/deposit', [BookingAdminController::class, 'verifyDeposit'])->name('bookings.deposit');
    });

    // Safeguarding reports can be submitted by agency leadership; confidential case details are visible only to the assigned safeguarding specialist.
    Route::middleware(['auth', 'role:super_admin,owner,admin', 'admin.session-current', 'admin.mfa'])->prefix('safeguarding/reports')->name('safeguarding.reports.')->group(function (): void {
        Route::get('/create', [SafeguardingCaseController::class, 'create'])->name('create');
        Route::post('/', [SafeguardingCaseController::class, 'store'])->middleware('throttle:5,1')->name('store');
    });

    Route::middleware(['auth', 'role:safeguarding', 'admin.session-current', 'admin.mfa'])->prefix('safeguarding/cases')->name('safeguarding.cases.')->group(function (): void {
        Route::get('/', [SafeguardingCaseController::class, 'index'])->name('index');
        Route::get('/{case}', [SafeguardingCaseController::class, 'show'])->name('show');
        Route::patch('/{case}', [SafeguardingCaseController::class, 'update'])->middleware('throttle:10,1')->name('update');
    });

    // Governance users may manage preservation metadata but never read confidential case content.
    Route::middleware(['auth', 'role:super_admin,owner', 'admin.session-current', 'admin.mfa'])->prefix('safeguarding/governance')->name('safeguarding.governance.')->group(function (): void {
        Route::get('/', [SafeguardingGovernanceController::class, 'index'])->name('index');
        Route::post('/{case}/legal-hold', [SafeguardingGovernanceController::class, 'placeLegalHold'])->middleware('throttle:5,1')->name('place-hold');
        Route::post('/{case}/release-legal-hold', [SafeguardingGovernanceController::class, 'releaseLegalHold'])->middleware('throttle:5,1')->name('release-hold');
    });

    // Finance specialists are isolated to the model settlement workspace.
    Route::middleware(['auth', 'role:super_admin,owner,finance', 'admin.session-current', 'admin.mfa'])->group(function (): void {
        Route::get('/model-settlements', [ModelTalentSettlementAdminController::class, 'index'])->name('model-settlements.index');
        Route::post('/model-assignments/{assignment}/expenses', [ModelTalentSettlementAdminController::class, 'addExpense'])->middleware(['feature:model-agency.settlements', 'throttle:10,1'])->name('model-settlements.expenses.store');
        Route::post('/model-settlement-expenses/{expense}/review', [ModelTalentSettlementAdminController::class, 'reviewExpense'])->middleware('throttle:10,1')->name('model-settlements.expenses.review');
        Route::post('/model-assignments/{assignment}/settlement', [ModelTalentSettlementAdminController::class, 'create'])->middleware(['feature:model-agency.settlements', 'throttle:5,1'])->name('model-settlements.create');
        Route::post('/model-settlements/{settlement}/approve', [ModelTalentSettlementAdminController::class, 'approve'])->middleware('throttle:5,1')->name('model-settlements.approve');
        Route::post('/model-settlements/{settlement}/payment', [ModelTalentSettlementAdminController::class, 'pay'])->middleware('throttle:5,1')->name('model-settlements.payment');
        Route::post('/model-settlements/{settlement}/reverse', [ModelTalentSettlementAdminController::class, 'reverse'])->middleware('throttle:5,1')->name('model-settlements.reverse');
        Route::post('/model-settlements/{settlement}/dispute', [ModelTalentSettlementAdminController::class, 'dispute'])->middleware('throttle:5,1')->name('model-settlements.dispute');
    });

    Route::post('/logout', [AdminLoginController::class, 'destroy'])
        ->middleware(['auth', 'role:super_admin,owner,admin,finance,verifier,safeguarding'])
        ->name('logout');
});
