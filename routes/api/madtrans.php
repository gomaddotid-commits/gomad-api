<?php

use App\Http\Controllers\Api\V1\MadTrans\Customer\MadTransController;
use App\Http\Controllers\Api\V1\MadTrans\Rental\RentalController;
use App\Http\Controllers\Api\V1\MadTrans\Rental\RentalDocumentController;
use App\Http\Controllers\Api\V1\MadTrans\Travel\DriverScheduleController;
use App\Http\Controllers\Api\V1\MadTrans\Travel\MadTransAgencyController;
use Illuminate\Support\Facades\Route;

/*
| API Mad Trans — pelanggan, supir, dan agency (travel & rental).
|
| Sejak satu akun satu fungsi, kelompok rute juga dijaga `area:` seperti web:
| akun pelanggan tidak bisa memanggil endpoint agency, dan sebaliknya. Ini bukan
| pengganti izin per aksi di controller — keduanya berlaku.
*/

// ---- Pelanggan: cari jadwal, pesan, bayar, lacak ----
Route::middleware(['auth:sanctum', 'area:customer'])->group(function (): void {
    Route::get('/mad-trans/schedules', [MadTransController::class, 'schedules'])->name('api.v1.mad-trans.schedules.index');
    Route::get('/mad-trans/schedules/{schedule}/seats', [MadTransController::class, 'seats'])
        ->name('api.v1.mad-trans.schedules.seats');
    Route::post('/mad-trans/schedules/{schedule}/pickup-quote', [MadTransController::class, 'pickupQuote'])
        ->name('api.v1.mad-trans.schedules.pickup-quote');
    Route::get('/mad-trans/bookings', [MadTransController::class, 'bookings'])->name('api.v1.mad-trans.bookings.index');
    Route::post('/mad-trans/bookings', [MadTransController::class, 'storeBooking'])->name('api.v1.mad-trans.bookings.store');
    Route::get('/mad-trans/bookings/{booking}', [MadTransController::class, 'showBooking'])->name('api.v1.mad-trans.bookings.show');
    Route::get('/mad-trans/bookings/{booking}/cancellation', [MadTransController::class, 'cancellation'])
        ->name('api.v1.mad-trans.bookings.cancellation');
    Route::get('/mad-trans/bookings/{booking}/tracking', [MadTransController::class, 'tracking'])
        ->name('api.v1.mad-trans.bookings.tracking');
    Route::post('/mad-trans/bookings/{booking}/pay', [MadTransController::class, 'payBooking'])->name('api.v1.mad-trans.bookings.pay');
    Route::post('/mad-trans/bookings/{booking}/cancel', [MadTransController::class, 'cancelBooking'])
        ->name('api.v1.mad-trans.bookings.cancel');

    // Rental: pelanggan memilih kendaraan, memesan, membayar, dan mengunggah dokumen.
    Route::get('/mad-trans/businesses/{business}/rental-vehicles', [RentalController::class, 'vehicles'])
        ->name('api.v1.rental.vehicles');
    Route::post('/mad-trans/businesses/{business}/rental-bookings', [RentalController::class, 'store'])
        ->name('api.v1.rental.store');
    Route::get('/mad-trans/rental-bookings', [RentalController::class, 'index'])->name('api.v1.rental.index');
    Route::get('/mad-trans/rental-bookings/{rental}', [RentalController::class, 'show'])->name('api.v1.rental.show');
    Route::post('/mad-trans/rental-bookings/{rental}/pay', [RentalController::class, 'pay'])
        ->name('api.v1.rental.pay');

    Route::get('/rental-documents', [RentalDocumentController::class, 'index'])->name('api.v1.rental-documents.index');
    Route::post('/rental-documents', [RentalDocumentController::class, 'store'])->name('api.v1.rental-documents.store');
});

// ---- Supir: tugas yang ditugaskan, boarding penumpang COD ----
Route::middleware(['auth:sanctum', 'area:driver'])->group(function (): void {
    Route::get('/mad-trans/driver/schedules', [DriverScheduleController::class, 'index'])
        ->name('api.v1.mad-trans.driver.schedules.index');
    Route::get('/mad-trans/driver/schedules/{schedule}', [DriverScheduleController::class, 'show'])
        ->whereNumber('schedule')
        ->name('api.v1.mad-trans.driver.schedules.show');
    Route::post('/mad-trans/bookings/{booking}/passengers/{passenger}/board', [MadTransController::class, 'boardCodPassenger'])
        ->name('api.v1.mad-trans.bookings.passengers.board');
    Route::post('/mad-trans/bookings/{booking}/passengers/{passenger}/no-show', [MadTransController::class, 'markCodNoShow'])
        ->name('api.v1.mad-trans.bookings.passengers.no-show');
});

// ---- Agency: rute, armada, jadwal, dompet, pesanan, meja rental ----
Route::middleware(['auth:sanctum', 'area:agency'])->group(function (): void {
    Route::get('/mad-trans/businesses/{business}/routes', [MadTransAgencyController::class, 'routes'])
        ->name('api.v1.mad-trans.routes.index');
    Route::post('/mad-trans/businesses/{business}/routes', [MadTransAgencyController::class, 'storeRoute'])
        ->name('api.v1.mad-trans.routes.store');
    Route::get('/mad-trans/businesses/{business}/vehicles', [MadTransAgencyController::class, 'vehicles'])
        ->name('api.v1.mad-trans.vehicles.index');
    Route::post('/mad-trans/businesses/{business}/vehicles', [MadTransAgencyController::class, 'storeVehicle'])
        ->name('api.v1.mad-trans.vehicles.store');
    Route::get('/mad-trans/businesses/{business}/schedules', [MadTransAgencyController::class, 'schedules'])
        ->name('api.v1.mad-trans.schedules.manage.index');
    Route::post('/mad-trans/businesses/{business}/schedules', [MadTransAgencyController::class, 'storeSchedule'])
        ->name('api.v1.mad-trans.schedules.store');
    Route::get('/mad-trans/businesses/{business}/wallet', [MadTransAgencyController::class, 'wallet'])
        ->name('api.v1.mad-trans.wallet.show');
    Route::get('/mad-trans/businesses/{business}/bookings', [MadTransAgencyController::class, 'bookings'])
        ->name('api.v1.mad-trans.businesses.bookings.index');
    Route::get('/mad-trans/businesses/{business}/withdrawals', [MadTransAgencyController::class, 'withdrawals'])
        ->name('api.v1.mad-trans.businesses.withdrawals.index');
    Route::get('/mad-trans/businesses/{business}/rental-bookings', [MadTransAgencyController::class, 'rentalBookings'])
        ->name('api.v1.mad-trans.businesses.rental-bookings.index');
});

// ---- Memulai & menutup perjalanan: agency menyetujui, supir menjalankan ----
Route::middleware(['auth:sanctum', 'area:agency,driver'])->group(function (): void {
    Route::post('/mad-trans/schedules/{schedule}/start', [MadTransAgencyController::class, 'startSchedule'])
        ->name('api.v1.mad-trans.schedules.start');
    Route::post('/mad-trans/schedules/{schedule}/finish', [MadTransAgencyController::class, 'finishSchedule'])
        ->name('api.v1.mad-trans.schedules.finish');
});

Route::middleware(['auth:sanctum', 'area:agency'])->group(function (): void {
    Route::post('/mad-trans/schedules/{schedule}/approve', [MadTransAgencyController::class, 'approveSchedule'])
        ->name('api.v1.mad-trans.schedules.approve');
    Route::post('/mad-trans/schedules/{schedule}/enable-cod', [MadTransAgencyController::class, 'enableCod'])
        ->name('api.v1.mad-trans.schedules.enable-cod');
});
