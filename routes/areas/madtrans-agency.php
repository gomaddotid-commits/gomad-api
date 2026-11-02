<?php

use App\Http\Controllers\Web\MadTrans\Customer\TrackingController;
use App\Http\Controllers\Web\MadTrans\Travel\AgencyPortalController;
use App\Http\Controllers\Web\MadTrans\Travel\AgencyPromoController;
use App\Http\Controllers\Web\MadTrans\Travel\AgencyTravelController;
use Illuminate\Support\Facades\Route;

/*
| Mad Trans — travel & rental milik agency.
|
| Semua rute di sini memerlukan akun `agency_owner` atau `agency_staff`. Supir
| punya areanya sendiri (routes/areas/madtrans-driver.php) dan pelanggan punya
| areanya sendiri (routes/areas/madtrans-customer.php) — tidak saling menumpang.
*/

Route::get('/mad-trans/agency', [AgencyTravelController::class, 'index'])->name('mad-trans.agency.index');

Route::get('/mad-trans/agency/{business}', [AgencyPortalController::class, 'dashboard'])
    ->whereNumber('business')
    ->name('mad-trans.agency.show');
Route::get('/mad-trans/agency/{business}/armada', [AgencyPortalController::class, 'fleet'])
    ->whereNumber('business')
    ->name('mad-trans.agency.fleet');
Route::get('/mad-trans/agency/{business}/rute', [AgencyPortalController::class, 'routes'])
    ->whereNumber('business')
    ->name('mad-trans.agency.routes');
Route::get('/mad-trans/agency/{business}/jadwal', [AgencyPortalController::class, 'schedules'])
    ->whereNumber('business')
    ->name('mad-trans.agency.schedules');
Route::get('/mad-trans/agency/{business}/pengemudi', [AgencyPortalController::class, 'drivers'])
    ->whereNumber('business')
    ->name('mad-trans.agency.drivers');
Route::get('/mad-trans/agency/{business}/pesanan', [AgencyPortalController::class, 'bookings'])
    ->whereNumber('business')
    ->name('mad-trans.agency.bookings');
Route::get('/mad-trans/agency/{business}/dompet', [AgencyPortalController::class, 'wallet'])
    ->whereNumber('business')
    ->name('mad-trans.agency.wallet');
Route::get('/mad-trans/agency/{business}/pencairan', [AgencyPortalController::class, 'withdrawals'])
    ->whereNumber('business')
    ->name('mad-trans.agency.withdrawals');
Route::get('/mad-trans/agency/{business}/laporan', [AgencyPortalController::class, 'reports'])
    ->whereNumber('business')
    ->name('mad-trans.agency.reports');
Route::get('/mad-trans/agency/{business}/pelacakan', [TrackingController::class, 'agency'])
    ->whereNumber('business')
    ->name('mad-trans.agency.tracking');
Route::get('/mad-trans/agency/{business}/rental', [AgencyPortalController::class, 'rental'])
    ->whereNumber('business')
    ->name('mad-trans.agency.rental');

Route::post('/mad-trans/agency/{business}/schedules', [AgencyTravelController::class, 'storeSchedule'])
    ->whereNumber('business')
    ->name('mad-trans.agency.schedules.store');
Route::get('/mad-trans/agency/{business}/schedules/{schedule}', [AgencyTravelController::class, 'showSchedule'])
    ->whereNumber('business')
    ->whereNumber('schedule')
    ->name('mad-trans.agency.schedules.show');
Route::post('/mad-trans/agency/{business}/vehicles', [AgencyTravelController::class, 'storeVehicle'])
    ->whereNumber('business')
    ->name('mad-trans.agency.vehicles.store');
Route::post('/mad-trans/agency/{business}/routes', [AgencyTravelController::class, 'storeRoute'])
    ->whereNumber('business')
    ->name('mad-trans.agency.routes.store');

Route::get('/mad-trans/agency/{business}/promos', [AgencyPromoController::class, 'index'])
    ->whereNumber('business')
    ->name('mad-trans.agency.promos.index');
Route::post('/mad-trans/agency/{business}/promos', [AgencyPromoController::class, 'store'])
    ->whereNumber('business')
    ->middleware('throttle:promo')
    ->name('mad-trans.agency.promos.store');

Route::post('/mad-trans/agency/{business}/collateral', [AgencyTravelController::class, 'topUpCollateral'])
    ->whereNumber('business')
    ->name('mad-trans.agency.collateral.store');
Route::post('/mad-trans/agency/{business}/withdrawals', [AgencyTravelController::class, 'storeWithdrawal'])
    ->whereNumber('business')
    ->name('mad-trans.agency.withdrawals.store');

Route::post('/mad-trans/agency/{business}/vehicles/{vehicle}/rental-rate', [AgencyTravelController::class, 'storeRentalRate'])
    ->whereNumber(['business', 'vehicle'])
    ->name('mad-trans.agency.rental-rate.store');
Route::post('/mad-trans/agency/{business}/rentals/{rental}/paid', [AgencyTravelController::class, 'markRentalPaid'])
    ->whereNumber(['business', 'rental'])
    ->name('mad-trans.agency.rentals.paid');
Route::post('/mad-trans/agency/{business}/rentals/{rental}/cancel', [AgencyTravelController::class, 'cancelRental'])
    ->whereNumber(['business', 'rental'])
    ->name('mad-trans.agency.rentals.cancel');
Route::get('/mad-trans/agency/{business}/rentals/{rental}/documents', [AgencyTravelController::class, 'rentalDocuments'])
    ->whereNumber(['business', 'rental'])
    ->name('mad-trans.agency.rentals.documents');
Route::post('/mad-trans/agency/{business}/rentals/{rental}/start', [AgencyTravelController::class, 'startRental'])
    ->whereNumber(['business', 'rental'])
    ->name('mad-trans.agency.rentals.start');
Route::post('/mad-trans/agency/{business}/rentals/{rental}/return', [AgencyTravelController::class, 'returnRental'])
    ->whereNumber(['business', 'rental'])
    ->name('mad-trans.agency.rentals.return');
Route::post('/mad-trans/agency/{business}/rentals/{rental}/complete', [AgencyTravelController::class, 'completeRental'])
    ->whereNumber(['business', 'rental'])
    ->name('mad-trans.agency.rentals.complete');

// Titik kumpul (koordinat) dan kebijakan radius jemput.
Route::put('/mad-trans/agency/{business}/stops/{stop}/pickup-point', [AgencyTravelController::class, 'updateStopPoint'])
    ->whereNumber(['business', 'stop'])
    ->name('mad-trans.agency.stops.pickup-point');
Route::put('/mad-trans/agency/{business}/pickup-policy', [AgencyTravelController::class, 'savePickupPolicy'])
    ->whereNumber('business')
    ->name('mad-trans.agency.pickup-policy');

Route::post('/mad-trans/agency/schedules/{schedule}/approve', [AgencyTravelController::class, 'approve'])
    ->whereNumber('schedule')
    ->name('mad-trans.agency.schedules.approve');
Route::post('/mad-trans/agency/schedules/{schedule}/enable-cod', [AgencyTravelController::class, 'enableCod'])
    ->whereNumber('schedule')
    ->name('mad-trans.agency.schedules.enable-cod');
Route::post('/mad-trans/agency/schedules/{schedule}/start', [AgencyTravelController::class, 'start'])
    ->whereNumber('schedule')
    ->name('mad-trans.agency.schedules.start');
Route::post('/mad-trans/agency/schedules/{schedule}/finish', [AgencyTravelController::class, 'finish'])
    ->whereNumber('schedule')
    ->name('mad-trans.agency.schedules.finish');
Route::post('/mad-trans/agency/{business}/bookings/{booking}/transfer', [AgencyTravelController::class, 'transferBooking'])
    ->whereNumber(['business', 'booking'])
    ->name('mad-trans.agency.bookings.transfer');
