<?php

use App\Http\Controllers\Api\V1\MadWarung\WarungCatalogController;
use App\Http\Controllers\Api\V1\MadWarung\WarungMemberLimitController;
use App\Http\Controllers\Api\V1\MadWarung\WarungOrderController;
use App\Http\Controllers\Api\V1\MadWarung\WarungReportController;
use App\Http\Controllers\Api\V1\MadWarung\WarungShiftController;
use Illuminate\Support\Facades\Route;

/*
| API Mad Warung — dipakai retail (pembeli stok) dan grosir (penjual).
|
| Satu set endpoint untuk keduanya karena yang membedakan hanya peran anggota
| usaha dan tipe usaha; izin per aksi di controller yang memutuskan siapa boleh
| memesan, menyetujui, menyiapkan, atau menerima. Grup rute dijaga `area:` supaya
| akun pelanggan/agency tidak bisa memanggil endpoint ini sama sekali.
*/
Route::middleware(['auth:sanctum', 'area:retail,grosir'])->group(function (): void {
    Route::get('/warung/wholesalers', [WarungCatalogController::class, 'wholesalers'])
        ->name('api.v1.warung.wholesalers.index');
    Route::get('/warung/catalog', [WarungCatalogController::class, 'index'])
        ->name('api.v1.warung.catalog.index');
    Route::put('/warung/grosir/{wholesaler}/products/{product}/price', [WarungCatalogController::class, 'setPrice'])
        ->name('api.v1.warung.grosir.products.price');

    Route::get('/warung/businesses/{business}/member-limits', [WarungMemberLimitController::class, 'index'])
        ->name('api.v1.warung.member-limits.index');
    Route::put('/warung/businesses/{business}/members/{member}/limit', [WarungMemberLimitController::class, 'update'])
        ->name('api.v1.warung.member-limits.update');

    Route::get('/warung/orders', [WarungOrderController::class, 'index'])->name('api.v1.warung.orders.index');
    Route::post('/warung/orders', [WarungOrderController::class, 'store'])->name('api.v1.warung.orders.store');
    Route::get('/warung/orders/{order}', [WarungOrderController::class, 'show'])->name('api.v1.warung.orders.show');
    Route::post('/warung/orders/{order}/decide', [WarungOrderController::class, 'decide'])
        ->name('api.v1.warung.orders.decide');
    Route::post('/warung/orders/{order}/pay', [WarungOrderController::class, 'pay'])
        ->name('api.v1.warung.orders.pay');
    Route::post('/warung/orders/{order}/fulfill', [WarungOrderController::class, 'fulfill'])
        ->name('api.v1.warung.orders.fulfill');
    Route::post('/warung/orders/{order}/receive', [WarungOrderController::class, 'receive'])
        ->name('api.v1.warung.orders.receive');
    Route::post('/warung/orders/{order}/complete', [WarungOrderController::class, 'complete'])
        ->name('api.v1.warung.orders.complete');

    Route::get('/warung/reports', [WarungReportController::class, 'show'])->name('api.v1.warung.reports.show');

    Route::get('/warung/businesses/{business}/shifts', [WarungShiftController::class, 'index'])
        ->name('api.v1.warung.shifts.index');
    Route::post('/warung/businesses/{business}/shifts', [WarungShiftController::class, 'schedule'])
        ->name('api.v1.warung.shifts.schedule');
    Route::post('/warung/shifts/{shift}/clock-in', [WarungShiftController::class, 'clockIn'])
        ->name('api.v1.warung.shifts.clock-in');
    Route::post('/warung/shifts/{shift}/clock-out', [WarungShiftController::class, 'clockOut'])
        ->name('api.v1.warung.shifts.clock-out');
});
