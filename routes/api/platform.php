<?php

use App\Http\Controllers\Api\V1\MadWarung\WarungCatalogController;
use App\Http\Controllers\Api\V1\Money\WithdrawalController;
use App\Http\Controllers\Api\V1\Platform\AdminConsoleController;
use App\Http\Controllers\Api\V1\Shared\SupportController;
use Illuminate\Support\Facades\Route;

/*
| API konsol platform (akun ops & admin).
|
| Berisi antrean pencairan, ringkasan operasional, verifikasi mitra, rekonsiliasi,
| pengguna & parameter, antrean dukungan, dan katalog grosir platform. Izin per
| aksi memisahkan pekerjaan ops dari administrasi admin; middleware `area:ops`
| menjaga supaya akun mitra atau pelanggan tidak bisa masuk sama sekali.
*/
Route::middleware(['auth:sanctum', 'area:ops'])->group(function (): void {
    Route::prefix('admin/withdrawals')->name('api.v1.admin.withdrawals.')->group(function (): void {
        Route::get('/', [AdminConsoleController::class, 'withdrawals'])
            ->name('index');
        Route::get('/{withdrawal}', [WithdrawalController::class, 'adminShow'])->name('show');
        Route::post('/{withdrawal}/approve', [WithdrawalController::class, 'approve'])->name('approve');
        Route::post('/{withdrawal}/complete', [WithdrawalController::class, 'complete'])->name('complete');
        Route::post('/{withdrawal}/fail', [WithdrawalController::class, 'fail'])->name('fail');
    });

    Route::prefix('admin')->name('api.v1.admin.')->group(function (): void {
        Route::get('/overview', [AdminConsoleController::class, 'overview'])->name('overview');
        Route::get('/partners', [AdminConsoleController::class, 'partners'])->name('partners.index');
        Route::post('/partners/{business}/verify', [AdminConsoleController::class, 'verifyPartner'])
            ->whereNumber('business')
            ->name('partners.verify');
        Route::get('/reconciliation', [AdminConsoleController::class, 'reconciliation'])->name('reconciliation');
        Route::get('/users', [AdminConsoleController::class, 'users'])->name('users.index');
        Route::get('/parameters', [AdminConsoleController::class, 'parameters'])->name('parameters');
        Route::get('/support/tickets', [SupportController::class, 'adminIndex'])->name('support.index');
        Route::post('/warung/products', [WarungCatalogController::class, 'storeProduct'])
            ->name('api.v1.admin.warung.products.store');
    });
});
