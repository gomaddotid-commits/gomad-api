<?php

use App\Http\Controllers\Web\Platform\Admin\AdminConsoleController;
use App\Http\Controllers\Web\Platform\Admin\AdminDocumentController;
use App\Http\Controllers\Web\Platform\Admin\AdminPromoController;
use App\Http\Controllers\Web\Platform\Admin\AdminSupportController;
use Illuminate\Support\Facades\Route;

/*
| Konsol platform.
|
| Dipakai akun `ops` dan `admin`. Middleware `area:ops` menjaga pintunya; pemisahan
| halaman yang hanya boleh dibuka admin (pengguna, parameter, settlement, promo)
| dilakukan lewat izin `ops.view` dan turunannya di AccessControlSeeder.
*/

Route::get('/admin', [AdminConsoleController::class, 'dashboard'])->name('admin.dashboard');

Route::get('/admin/pencairan', [AdminConsoleController::class, 'withdrawals'])->name('admin.withdrawals');
Route::post('/admin/pencairan/{withdrawal}/setujui', [AdminConsoleController::class, 'approveWithdrawal'])
    ->whereNumber('withdrawal')
    ->name('admin.withdrawals.approve');
Route::post('/admin/pencairan/{withdrawal}/selesai', [AdminConsoleController::class, 'completeWithdrawal'])
    ->whereNumber('withdrawal')
    ->name('admin.withdrawals.complete');
Route::post('/admin/pencairan/{withdrawal}/gagal', [AdminConsoleController::class, 'failWithdrawal'])
    ->whereNumber('withdrawal')
    ->name('admin.withdrawals.fail');

Route::get('/admin/settlement', [AdminConsoleController::class, 'settlement'])->name('admin.settlement');
Route::post('/admin/settlement/{business}', [AdminConsoleController::class, 'settle'])
    ->whereNumber('business')
    ->name('admin.settlement.store');
Route::get('/admin/rekonsiliasi', [AdminConsoleController::class, 'reconciliation'])->name('admin.reconciliation');

Route::get('/admin/mitra', [AdminConsoleController::class, 'partners'])->name('admin.partners');
Route::post('/admin/mitra/{business}/verifikasi', [AdminConsoleController::class, 'verifyPartner'])
    ->whereNumber('business')
    ->name('admin.partners.verify');

Route::get('/admin/pengguna', [AdminConsoleController::class, 'users'])->name('admin.users');
Route::get('/admin/parameter', [AdminConsoleController::class, 'parameters'])->name('admin.parameters');

Route::get('/admin/dukungan', [AdminSupportController::class, 'index'])->name('admin.support.index');
Route::get('/admin/dukungan/{ticket}', [AdminSupportController::class, 'show'])
    ->whereNumber('ticket')
    ->name('admin.support.show');
Route::post('/admin/dukungan/{ticket}/balas', [AdminSupportController::class, 'reply'])
    ->whereNumber('ticket')
    ->name('admin.support.reply');
Route::post('/admin/dukungan/{ticket}/tutup', [AdminSupportController::class, 'close'])
    ->whereNumber('ticket')
    ->name('admin.support.close');

Route::get('/admin/rental-documents', [AdminDocumentController::class, 'index'])
    ->name('admin.rental-documents.index');
Route::post('/admin/rental-documents/{document}/verify', [AdminDocumentController::class, 'verify'])
    ->whereNumber('document')
    ->name('admin.rental-documents.verify');
Route::post('/admin/rental-documents/{document}/reject', [AdminDocumentController::class, 'reject'])
    ->whereNumber('document')
    ->name('admin.rental-documents.reject');

Route::get('/admin/promos', [AdminPromoController::class, 'index'])->name('admin.promos.index');
Route::post('/admin/promos', [AdminPromoController::class, 'store'])
    ->middleware('throttle:promo')
    ->name('admin.promos.store');
