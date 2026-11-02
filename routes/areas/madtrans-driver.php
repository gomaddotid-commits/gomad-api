<?php

use App\Http\Controllers\Web\MadTrans\Driver\DriverTravelController;
use Illuminate\Support\Facades\Route;

/*
| Mad Trans — ruang kerja supir.
|
| Supir hanya melihat jadwal yang ditugaskan kepadanya, menaikkan penumpang,
| dan menutup perjalanan. Tidak ada rute agency atau pelanggan di sini.
*/

Route::get('/mad-trans/driver', [DriverTravelController::class, 'index'])->name('mad-trans.driver.index');
Route::get('/mad-trans/driver/schedules/{schedule}', [DriverTravelController::class, 'show'])
    ->whereNumber('schedule')
    ->name('mad-trans.driver.schedules.show');
Route::post('/mad-trans/driver/schedules/{schedule}/start', [DriverTravelController::class, 'start'])
    ->whereNumber('schedule')
    ->name('mad-trans.driver.schedules.start');
Route::post('/mad-trans/driver/schedules/{schedule}/finish', [DriverTravelController::class, 'finish'])
    ->whereNumber('schedule')
    ->name('mad-trans.driver.schedules.finish');
Route::post('/mad-trans/driver/bookings/{booking}/passengers/{passenger}/board', [DriverTravelController::class, 'board'])
    ->whereNumber(['booking', 'passenger'])
    ->name('mad-trans.driver.passengers.board');
Route::post('/mad-trans/driver/bookings/{booking}/passengers/{passenger}/no-show', [DriverTravelController::class, 'noShow'])
    ->whereNumber(['booking', 'passenger'])
    ->name('mad-trans.driver.passengers.no-show');
