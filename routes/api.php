<?php

use Illuminate\Support\Facades\Route;

/*
| API v1 GoMad.
|
| Berkas ini hanya titik masuk: kesehatan + empat berkas area. Satu area = satu
| berkas, sama seperti `routes/areas/*` untuk web, supaya permukaan API mudah
| dicari dan tidak ada endpoint yang "nyasar" di berkas raksasa.
|
|   api/shared.php    auth, identitas, izin, usaha, notifikasi, dukungan,
|                     promo/referral/level, publik, webhook, uang
|   api/madwarung.php katalog, pesanan, plafon penjaga, shift, laporan
|   api/madtrans.php  pelanggan (cari/pesan/bayar/lacak), supir (boarding), agency
|                     (rute, armada, jadwal, dompet), rental + dokumen
|   api/platform.php  antrean ops & administrasi platform
*/
Route::get('/health', fn () => response()->json([
    'data' => [
        'status' => 'ok',
    ],
]))->name('api.v1.health');

Route::group([], base_path('routes/api/shared.php'));
Route::group([], base_path('routes/api/madwarung.php'));
Route::group([], base_path('routes/api/madtrans.php'));
Route::group([], base_path('routes/api/platform.php'));
