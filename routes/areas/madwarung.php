<?php

use App\Http\Controllers\Web\MadWarung\RetailPosController;
use App\Http\Controllers\Web\MadWarung\WarungController;
use App\Http\Controllers\Web\MadWarung\WarungPortalController;
use Illuminate\Support\Facades\Route;

/*
| Mad Warung — retail dan grosir.
|
| Satu controller, dua wajah: warung melihat pesanan dan penjaga, grosir melihat
| pesanan masuk dan harga grosir. Keduanya berbagi area `retail`/`grosir` di
| middleware, jadi akun pelanggan atau agency tidak bisa masuk ke sini.
|
| URL sengaja tetap datar (`/mad-warung/...`). Pembagian folder internal
| (MadWarung\Retail, MadWarung\Grosir, MadWarung\Guard) menyusul di fase
| restrukturisasi; yang dijaga di sini adalah hak masuk.
*/

Route::get('/mad-warung', [WarungController::class, 'index'])->name('warung.index');

Route::get('/mad-warung/{business}', [WarungPortalController::class, 'dashboard'])
    ->whereNumber('business')
    ->name('warung.show');
Route::get('/mad-warung/{business}/pesanan', [WarungPortalController::class, 'orders'])
    ->whereNumber('business')
    ->name('warung.orders.index');
Route::get('/mad-warung/{business}/katalog', [WarungPortalController::class, 'catalog'])
    ->whereNumber('business')
    ->name('warung.catalog');
Route::get('/mad-warung/{business}/penjaga', [WarungPortalController::class, 'guards'])
    ->whereNumber('business')
    ->name('warung.guards');
Route::get('/mad-warung/{business}/shift', [WarungPortalController::class, 'shifts'])
    ->whereNumber('business')
    ->name('warung.shifts');
Route::get('/mad-warung/{business}/laporan', [WarungPortalController::class, 'report'])
    ->whereNumber('business')
    ->name('warung.report');

/*
| Kasir (POS) — hanya warung retail, bukan grosir.
|
| Penjaga menjalankan kasir (`pos.sell`); pemilik hanya memantau (`pos.report`).
| Pembatasan yang sebenarnya ada di izin, bukan di komentar ini — rute hanya
| memberi nama pada pekerjaannya.
*/
Route::get('/mad-warung/{business}/kasir', [RetailPosController::class, 'index'])
    ->whereNumber('business')
    ->name('warung.pos');
Route::post('/mad-warung/{business}/kasir', [RetailPosController::class, 'store'])
    ->whereNumber('business')
    ->name('warung.pos.store');
Route::get('/mad-warung/{business}/penjualan', [RetailPosController::class, 'report'])
    ->whereNumber('business')
    ->name('warung.pos.report');

Route::post('/mad-warung/{business}/orders', [WarungController::class, 'storeOrder'])->name('warung.orders.store');
Route::post('/mad-warung/orders/{order}/decide', [WarungController::class, 'decideOrder'])->name('warung.orders.decide');
Route::post('/mad-warung/orders/{order}/pay', [WarungController::class, 'payOrder'])->name('warung.orders.pay');
Route::post('/mad-warung/orders/{order}/fulfill', [WarungController::class, 'fulfillOrder'])->name('warung.orders.fulfill');
Route::post('/mad-warung/orders/{order}/receive', [WarungController::class, 'receiveOrder'])->name('warung.orders.receive');
Route::post('/mad-warung/orders/{order}/complete', [WarungController::class, 'completeOrder'])->name('warung.orders.complete');

Route::put('/mad-warung/{business}/members/{member}/limit', [WarungController::class, 'setLimit'])
    ->whereNumber('member')
    ->name('warung.members.limit');
Route::put('/mad-warung/{business}/products/{product}/price', [WarungController::class, 'setPrice'])
    ->name('warung.products.price');

Route::post('/mad-warung/{business}/shifts', [WarungController::class, 'scheduleShift'])->name('warung.shifts.schedule');
Route::post('/mad-warung/shifts/{shift}/clock-in', [WarungController::class, 'clockIn'])->name('warung.shifts.clock-in');
Route::post('/mad-warung/shifts/{shift}/clock-out', [WarungController::class, 'clockOut'])->name('warung.shifts.clock-out');
