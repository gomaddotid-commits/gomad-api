<?php

use App\Http\Controllers\Web\MadTrans\Customer\MadTransController;
use App\Http\Controllers\Web\MadTrans\Customer\RentalCalendarController;
use App\Http\Controllers\Web\MadTrans\Customer\RentalController;
use App\Http\Controllers\Web\MadTrans\Customer\RentalDocumentController;
use App\Http\Controllers\Web\MadTrans\Customer\TrackingController;
use Illuminate\Support\Facades\Route;

/*
| Mad Trans — sisi pelanggan.
|
| Mencari jadwal, memesan kursi, membayar, membatalkan, memantau perjalanan,
| dan menyewa kendaraan. Akun mitra tidak masuk ke sini: pemilik agency punya
| ruang kerjanya sendiri, dan kalau ingin memesan ia memakai akun pelanggan.
*/

Route::get('/mad-trans', [MadTransController::class, 'index'])->name('mad-trans.index');
Route::get('/mad-trans/bookings', [MadTransController::class, 'bookings'])->name('mad-trans.bookings.index');
Route::get('/mad-trans/bookings/{booking}', [MadTransController::class, 'booking'])
    ->whereNumber('booking')
    ->name('mad-trans.bookings.show');
Route::post('/mad-trans/bookings/{booking}/pay', [MadTransController::class, 'pay'])
    ->whereNumber('booking')
    ->middleware('throttle:money')
    ->name('mad-trans.bookings.pay');
Route::get('/mad-trans/bookings/{booking}/batalkan', [MadTransController::class, 'cancelForm'])
    ->whereNumber('booking')
    ->name('mad-trans.bookings.cancel-form');
Route::post('/mad-trans/bookings/{booking}/batalkan', [MadTransController::class, 'cancel'])
    ->whereNumber('booking')
    ->middleware('throttle:money')
    ->name('mad-trans.bookings.cancel');
Route::get('/mad-trans/bookings/{booking}/perjalanan', [TrackingController::class, 'booking'])
    ->whereNumber('booking')
    ->name('mad-trans.bookings.tracking');

Route::get('/mad-trans/schedules/{schedule}', [MadTransController::class, 'show'])
    ->whereNumber('schedule')
    ->name('mad-trans.schedules.show');
Route::get('/mad-trans/schedules/{schedule}/seats', [MadTransController::class, 'seats'])
    ->whereNumber('schedule')
    ->name('mad-trans.schedules.seats');
Route::post('/mad-trans/schedules/{schedule}/pickup-quote', [MadTransController::class, 'pickupQuote'])
    ->whereNumber('schedule')
    ->middleware('throttle:public-search')
    ->name('mad-trans.schedules.pickup-quote');
Route::post('/mad-trans/schedules/{schedule}/bookings', [MadTransController::class, 'store'])
    ->whereNumber('schedule')
    ->name('mad-trans.bookings.store');

// Rental: pelanggan menyewa kendaraan agency, termasuk unggah dokumen self-drive.
Route::get('/mad-trans/rental/{business}', [RentalController::class, 'show'])
    ->whereNumber('business')
    ->name('rental.show');
Route::post('/mad-trans/rental/{business}', [RentalController::class, 'store'])
    ->whereNumber('business')
    ->middleware('throttle:money')
    ->name('rental.store');
Route::post('/mad-trans/rental/{business}/rentals/{rental}/pay', [RentalController::class, 'pay'])
    ->whereNumber(['business', 'rental'])
    ->middleware('throttle:money')
    ->name('rental.pay');
Route::get('/mad-trans/rental/{business}/vehicles/{vehicle}/calendar', [RentalCalendarController::class, 'show'])
    ->whereNumber(['business', 'vehicle'])
    ->name('rental.calendar');
Route::get('/mad-trans/rental-documents', [RentalDocumentController::class, 'index'])
    ->name('rental-documents.index');
Route::post('/mad-trans/rental-documents', [RentalDocumentController::class, 'store'])
    ->middleware('throttle:uploads')
    ->name('rental-documents.store');
