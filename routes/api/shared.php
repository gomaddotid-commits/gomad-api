<?php

use App\Http\Controllers\Api\V1\Money\PaymentController;
use App\Http\Controllers\Api\V1\Money\PaymentWebhookController;
use App\Http\Controllers\Api\V1\Money\SettlementController;
use App\Http\Controllers\Api\V1\Money\WithdrawalController;
use App\Http\Controllers\Api\V1\Shared\AuthController;
use App\Http\Controllers\Api\V1\Shared\BusinessController;
use App\Http\Controllers\Api\V1\Shared\BusinessInvitationController;
use App\Http\Controllers\Api\V1\Shared\BusinessMemberController;
use App\Http\Controllers\Api\V1\Shared\EngagementController;
use App\Http\Controllers\Api\V1\Shared\IdentityController;
use App\Http\Controllers\Api\V1\Shared\NotificationController;
use App\Http\Controllers\Api\V1\Shared\PermissionController;
use App\Http\Controllers\Api\V1\Shared\PublicController;
use App\Http\Controllers\Api\V1\Shared\SupportController;
use Illuminate\Support\Facades\Route;

/*
| API bersama — bukan fungsi bisnis.
|
| Auth, identitas, izin, usaha & undangan, notifikasi, dukungan, promo/referral/level,
| permukaan publik, webhook pembayaran, dan uang (pembayaran & pencairan) dipakai
| semua fungsi akun. Yang membatasi isinya adalah izin per aksi di controller,
| bukan area rute.
*/

Route::prefix('auth')->name('api.v1.auth.')->middleware('throttle:auth')->group(function (): void {
    Route::post('/register', [AuthController::class, 'register'])->name('register');
    Route::post('/login', [AuthController::class, 'login'])->name('login');
    Route::post('/mfa/verify', [AuthController::class, 'verifyMfa'])->name('mfa.verify');
});

Route::post('/invitations/accept', [BusinessInvitationController::class, 'accept'])
    ->middleware('throttle:auth')
    ->name('api.v1.invitations.accept');

// Publik: tanpa token, hanya untuk melihat (dipakai halaman statis & aplikasi sebelum masuk).
Route::middleware('throttle:public-search')->group(function (): void {
    Route::get('/public/schedules', [PublicController::class, 'schedules'])->name('api.v1.public.schedules.index');
    Route::get('/public/schedules/{schedule}', [PublicController::class, 'schedule'])
        ->whereNumber('schedule')
        ->name('api.v1.public.schedules.show');
});
Route::get('/public/partners', [PublicController::class, 'partners'])->name('api.v1.public.partners.index');

Route::post('/webhooks/midtrans', [PaymentWebhookController::class, 'midtrans'])
    ->middleware('throttle:60,1')
    ->name('api.v1.webhooks.midtrans');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('api.v1.auth.logout');
    Route::post('/auth/refresh', [AuthController::class, 'refresh'])
        ->middleware('throttle:money')
        ->name('api.v1.auth.refresh');

    Route::get('/me/security', [IdentityController::class, 'security'])->name('api.v1.me.security');
    Route::post('/me/security/two-factor', [IdentityController::class, 'startTwoFactor'])
        ->middleware('throttle:auth')
        ->name('api.v1.me.security.start');
    Route::post('/me/security/two-factor/confirm', [IdentityController::class, 'confirmTwoFactor'])
        ->middleware('throttle:auth')
        ->name('api.v1.me.security.confirm');
    Route::delete('/me/security/two-factor', [IdentityController::class, 'disableTwoFactor'])
        ->middleware('throttle:auth')
        ->name('api.v1.me.security.disable');
    Route::get('/me', [IdentityController::class, 'show'])->name('api.v1.me.show');
    Route::patch('/me', [IdentityController::class, 'update'])->name('api.v1.me.update');
    Route::get('/me/memberships', [IdentityController::class, 'memberships'])->name('api.v1.me.memberships');
    Route::get('/permissions', [PermissionController::class, 'index'])->name('api.v1.permissions.index');

    // Uang: pembayaran (semua pembeli) dan pencairan (mitra meminta, platform memutuskan).
    Route::post('/payments/initiate', [PaymentController::class, 'store'])->name('api.v1.payments.initiate');
    Route::get('/payments/{payment}', [PaymentController::class, 'show'])->name('api.v1.payments.show');
    Route::post('/withdrawals', [WithdrawalController::class, 'store'])->name('api.v1.withdrawals.store');
    Route::get('/withdrawals/{withdrawal}', [WithdrawalController::class, 'show'])->name('api.v1.withdrawals.show');

    Route::get('/notifications', [NotificationController::class, 'index'])->name('api.v1.notifications.index');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'read'])
        ->name('api.v1.notifications.read');

    Route::get('/support/tickets', [SupportController::class, 'index'])->name('api.v1.support.index');
    Route::post('/support/tickets', [SupportController::class, 'store'])
        ->middleware('throttle:auth')
        ->name('api.v1.support.store');
    Route::get('/support/tickets/{ticket}', [SupportController::class, 'show'])
        ->whereNumber('ticket')
        ->name('api.v1.support.show');
    Route::post('/support/tickets/{ticket}/messages', [SupportController::class, 'reply'])
        ->whereNumber('ticket')
        ->name('api.v1.support.reply');
    Route::post('/support/tickets/{ticket}/close', [SupportController::class, 'close'])
        ->whereNumber('ticket')
        ->name('api.v1.support.close');

    Route::post('/promos/validate', [EngagementController::class, 'validatePromo'])->name('api.v1.promos.validate');
    Route::get('/referral', [EngagementController::class, 'referral'])->name('api.v1.referral.show');
    Route::get('/loyalty', [EngagementController::class, 'loyalty'])->name('api.v1.loyalty.show');

    Route::apiResource('businesses', BusinessController::class)
        ->only(['index', 'store', 'show', 'update'])
        ->parameters(['businesses' => 'business'])
        ->names('api.v1.businesses');
    Route::get('/businesses/{business}/members', [BusinessMemberController::class, 'index'])
        ->name('api.v1.businesses.members.index');
    Route::patch('/businesses/{business}/members/{membership}', [BusinessMemberController::class, 'update'])
        ->whereNumber('membership')
        ->name('api.v1.businesses.members.update');
    Route::delete('/businesses/{business}/members/{user}', [BusinessMemberController::class, 'destroy'])
        ->whereNumber('user')
        ->name('api.v1.businesses.members.destroy');
    Route::post('/businesses/{business}/invitations', [BusinessInvitationController::class, 'store'])
        ->name('api.v1.businesses.invitations.store');
    Route::post('/businesses/{business}/settlements', [SettlementController::class, 'store'])
        ->name('api.v1.businesses.settlements.store');
});
