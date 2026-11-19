<?php

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminLoginController;
use App\Http\Controllers\Admin\AdminMfaController;
use App\Http\Controllers\Admin\BookingAdminController;
use App\Http\Controllers\Admin\BookingSettingsController;
use App\Http\Controllers\Admin\BrandingController;
use App\Http\Controllers\Admin\CompanionProfileController;
use App\Http\Controllers\Admin\DestinationController;
use App\Http\Controllers\Admin\EnquiryAdminController;
use App\Http\Controllers\Admin\PaymentMethodController;
use App\Http\Controllers\Admin\PaymentSettingsController;
use App\Http\Controllers\Admin\SitePageController;
use App\Http\Controllers\AccountSecurityController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\CompanionBookingController;
use App\Http\Controllers\CompanionPortalController;
use App\Http\Controllers\DiscoveryController;
use App\Http\Controllers\EnquiryController;
use App\Http\Controllers\PortalAuthController;
use App\Http\Controllers\PortalDashboardController;
use App\Http\Controllers\PageController;
use App\Models\SitePage;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Support\Facades\Route;

Route::get('/', function (\App\Services\BrandSettings $settings) {
    return view('home', [
        'brand' => $settings->publicBrand(),
        'pages' => SitePage::query()->where('is_published', true)->orderBy('title')->get(['title', 'slug']),
    ]);
})->name('home');

Route::get('/destinations', [DiscoveryController::class, 'destinations'])->name('destinations.index');
Route::get('/destinations/{slug}', [DiscoveryController::class, 'destination'])->name('destinations.show');
Route::get('/companions', [DiscoveryController::class, 'companions'])->name('companions.index');
Route::get('/companions/{slug}', [DiscoveryController::class, 'companion'])->name('companions.show');
Route::get('/contact', [EnquiryController::class, 'create'])->name('contact');
Route::get('/pages/{slug}', [PageController::class, 'show'])->name('pages.show');
Route::view('/privacy', 'legal.privacy')->name('privacy');
Route::view('/terms', 'legal.terms')->name('terms');
Route::post('/enquiries', [EnquiryController::class, 'store'])->middleware('throttle:5,1')->name('enquiries.store');

Route::middleware('guest')->group(function (): void {
    Route::get('/register', [PortalAuthController::class, 'registerForm'])->name('register');
    Route::post('/register', [PortalAuthController::class, 'register'])->middleware('throttle:5,1')->name('register.store');
    Route::get('/login', [PortalAuthController::class, 'loginForm'])->name('login');
    Route::post('/login', [PortalAuthController::class, 'login'])->middleware('throttle:10,1')->name('login.store');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [PortalAuthController::class, 'logout'])->name('logout');
    Route::patch('/account/password', [AccountSecurityController::class, 'updatePassword'])->name('account.password.update');
    Route::get('/email/verify', [PortalAuthController::class, 'verificationNotice'])->name('verification.notice');
    Route::get('/email/verify/{id}/{hash}', function (EmailVerificationRequest $request) {
        $request->fulfill();

        return redirect()->route($request->user()->role === 'companion' ? 'companion-portal.dashboard' : 'portal.dashboard');
    })->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
    Route::post('/email/verification-notification', [PortalAuthController::class, 'resendVerification'])->middleware('throttle:6,1')->name('verification.send');

    Route::middleware(['verified', 'role:client'])->group(function (): void {
        Route::get('/portal', [PortalDashboardController::class, 'client'])->name('portal.dashboard');
        Route::get('/companions/{slug}/book', [BookingController::class, 'create'])->name('bookings.create');
        Route::post('/bookings', [BookingController::class, 'store'])->name('bookings.store');
        Route::post('/bookings/{booking}/cancel', [BookingController::class, 'cancel'])->name('bookings.cancel');
    });

    Route::middleware(['verified', 'role:companion'])->group(function (): void {
        Route::get('/companion-portal', [CompanionPortalController::class, 'index'])->name('companion-portal.dashboard');
        Route::post('/companion-portal/profiles/{profile}/automatic-booking', [CompanionPortalController::class, 'updateAutomaticBooking'])->name('companion-portal.profiles.automatic-booking');
        Route::post('/companion-portal/profiles/{profile}/availability', [CompanionPortalController::class, 'storeAvailability'])->name('companion-portal.profiles.availability.store');
        Route::delete('/companion-portal/profiles/{profile}/availability/{rule}', [CompanionPortalController::class, 'destroyAvailability'])->name('companion-portal.profiles.availability.destroy');
        Route::post('/companion-portal/profiles/{profile}/withdraw-consent', [CompanionPortalController::class, 'withdrawPublicationConsent'])->name('companion-portal.profiles.withdraw-consent');
        Route::patch('/companion-portal/bookings/{booking}', [CompanionBookingController::class, 'update'])->name('companion-portal.bookings.update');
    });
});

Route::prefix('admin')->name('admin.')->group(function (): void {
    Route::middleware('guest')->group(function (): void {
        Route::get('/login', [AdminLoginController::class, 'create'])->name('login');
        Route::post('/login', [AdminLoginController::class, 'store'])->middleware('throttle:10,1')->name('login.store');
    });

    Route::get('/mfa/challenge', [AdminMfaController::class, 'challenge'])->name('mfa.challenge');
    Route::post('/mfa/challenge', [AdminMfaController::class, 'verifyChallenge'])->middleware('throttle:5,1')->name('mfa.challenge.verify');
    Route::post('/mfa/recovery', [AdminMfaController::class, 'verifyRecovery'])->middleware('throttle:5,1')->name('mfa.recovery.verify');

    Route::middleware(['auth', 'role:owner,admin'])->group(function (): void {
        Route::get('/mfa/setup', [AdminMfaController::class, 'setup'])->name('mfa.setup');
        Route::post('/mfa/setup', [AdminMfaController::class, 'confirmSetup'])->middleware('throttle:5,1')->name('mfa.setup.confirm');
        Route::get('/mfa/recovery-codes', [AdminMfaController::class, 'recoveryCodes'])->name('mfa.recovery-codes');
    });

    Route::middleware(['auth', 'role:owner,admin', 'admin.mfa'])->group(function (): void {
        Route::get('/', AdminDashboardController::class)->name('dashboard');
        Route::get('/branding', [BrandingController::class, 'edit'])->name('branding.edit');
        Route::put('/branding', [BrandingController::class, 'update'])->name('branding.update');
        Route::resource('destinations', DestinationController::class)->except(['show', 'destroy']);
        Route::get('/profiles', [CompanionProfileController::class, 'index'])->name('profiles.index');
        Route::get('/profiles/create', [CompanionProfileController::class, 'create'])->name('profiles.create');
        Route::post('/profiles', [CompanionProfileController::class, 'store'])->name('profiles.store');
        Route::post('/profiles/{profile}/publish', [CompanionProfileController::class, 'publish'])->name('profiles.publish');
        Route::post('/profiles/{profile}/unpublish', [CompanionProfileController::class, 'unpublish'])->name('profiles.unpublish');
        Route::get('/enquiries', [EnquiryAdminController::class, 'index'])->name('enquiries.index');
        Route::patch('/enquiries/{enquiry}', [EnquiryAdminController::class, 'update'])->name('enquiries.update');
        Route::get('/bookings', [BookingAdminController::class, 'index'])->name('bookings.index');
        Route::patch('/bookings/{booking}', [BookingAdminController::class, 'update'])->name('bookings.update');
        Route::post('/bookings/{booking}/deposit', [BookingAdminController::class, 'verifyDeposit'])->name('bookings.deposit');
        Route::get('/booking-settings', [BookingSettingsController::class, 'edit'])->name('bookings.settings');
        Route::resource('pages', SitePageController::class)->except(['show', 'destroy']);
        Route::get('/payment-settings', [PaymentSettingsController::class, 'edit'])->name('payment-settings');
        Route::put('/payment-settings', [PaymentSettingsController::class, 'update'])->name('payment-settings.update');
        Route::get('/payment-methods', [PaymentMethodController::class, 'index'])->name('payment-methods');
        Route::post('/payment-methods', [PaymentMethodController::class, 'store'])->name('payment-methods.store');
        Route::post('/payment-methods/{paymentMethod}/review', [PaymentMethodController::class, 'review'])->name('payment-methods.review');
        Route::patch('/payment-methods/{paymentMethod}/enabled', [PaymentMethodController::class, 'toggle'])->name('payment-methods.enabled');
        Route::put('/booking-settings', [BookingSettingsController::class, 'update'])->name('bookings.settings.update');
        Route::post('/logout', [AdminLoginController::class, 'destroy'])->name('logout');
    });
});
