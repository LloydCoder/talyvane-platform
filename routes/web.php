<?php

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminLoginController;
use App\Http\Controllers\Admin\BrandingController;
use App\Http\Controllers\Admin\CompanionProfileController;
use App\Http\Controllers\Admin\DestinationController;
use App\Http\Controllers\Admin\EnquiryAdminController;
use App\Http\Controllers\DiscoveryController;
use App\Http\Controllers\EnquiryController;
use App\Http\Controllers\PortalAuthController;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function (\App\Services\BrandSettings $settings) {
    return view('home', ['brand' => $settings->publicBrand()]);
})->name('home');

Route::get('/destinations', [DiscoveryController::class, 'destinations'])->name('destinations.index');
Route::get('/destinations/{slug}', [DiscoveryController::class, 'destination'])->name('destinations.show');
Route::get('/companions', [DiscoveryController::class, 'companions'])->name('companions.index');
Route::get('/companions/{slug}', [DiscoveryController::class, 'companion'])->name('companions.show');
Route::get('/contact', [EnquiryController::class, 'create'])->name('contact');
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
    Route::get('/email/verify', [PortalAuthController::class, 'verificationNotice'])->name('verification.notice');
    Route::get('/email/verify/{id}/{hash}', function (EmailVerificationRequest $request) {
        $request->fulfill();

        return redirect()->route($request->user()->role === 'companion' ? 'companion-portal.dashboard' : 'portal.dashboard');
    })->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
    Route::post('/email/verification-notification', [PortalAuthController::class, 'resendVerification'])->middleware('throttle:6,1')->name('verification.send');
    Route::get('/portal', fn () => view('portal.client-dashboard'))->middleware(['verified', 'role:client'])->name('portal.dashboard');
    Route::get('/companion-portal', fn () => view('portal.companion-dashboard'))->middleware(['verified', 'role:companion'])->name('companion-portal.dashboard');
});

Route::prefix('admin')->name('admin.')->group(function (): void {
    Route::middleware('guest')->group(function (): void {
        Route::get('/login', [AdminLoginController::class, 'create'])->name('login');
        Route::post('/login', [AdminLoginController::class, 'store'])->middleware('throttle:10,1')->name('login.store');
    });

    Route::middleware(['auth', 'role:owner,admin'])->group(function (): void {
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
        Route::post('/logout', [AdminLoginController::class, 'destroy'])->name('logout');
    });
});
