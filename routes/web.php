<?php

use App\Http\Controllers\Web\MadTrans\Customer\RentalDocumentController;
use App\Http\Controllers\Web\Shared\AccountController;
use App\Http\Controllers\Web\Shared\AuthController;
use App\Http\Controllers\Web\Shared\BusinessController;
use App\Http\Controllers\Web\Shared\HomeController;
use App\Http\Controllers\Web\Shared\InvitationController;
use App\Http\Controllers\Web\Shared\LoyaltyController;
use App\Http\Controllers\Web\Shared\MfaController;
use App\Http\Controllers\Web\Shared\NotificationController;
use App\Http\Controllers\Web\Shared\PublicController;
use App\Http\Controllers\Web\Shared\ReferralController;
use App\Http\Controllers\Web\Shared\SupportController;
use Illuminate\Support\Facades\Route;

/*
| Permukaan publik — pengunjung tanpa akun.
|
| Pengunjung harus bisa melihat apa yang dijual dan berapa harganya sebelum diminta
| mendaftar. Yang tampil di sini hanya jadwal terbit, harga segmen, dan mitra aktif;
| pemesanan tetap di balik autentikasi.
*/
Route::get('/', [PublicController::class, 'home'])->name('home');
Route::get('/jadwal', [PublicController::class, 'search'])
    ->middleware('throttle:public-search')
    ->name('public.search');
Route::get('/jadwal/{schedule}', [PublicController::class, 'schedule'])
    ->whereNumber('schedule')
    ->name('public.schedule');
Route::get('/warung', [PublicController::class, 'warung'])->name('warung.public.index');
Route::get('/mitra', [PublicController::class, 'partners'])->name('public.partners');
Route::get('/unduh', [PublicController::class, 'download'])->name('public.download');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'loginForm'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:auth');
    Route::get('/register', [AuthController::class, 'registerForm'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:auth');
    Route::get('/masuk/verifikasi', [MfaController::class, 'challenge'])->name('mfa.challenge');
    Route::post('/masuk/verifikasi', [MfaController::class, 'verify'])
        ->middleware('throttle:auth')
        ->name('mfa.verify');
    Route::post('/masuk/verifikasi/kirim-ulang', [MfaController::class, 'resend'])
        ->middleware('throttle:auth')
        ->name('mfa.resend');
    Route::post('/masuk/verifikasi/batal', [MfaController::class, 'cancel'])->name('mfa.cancel');
});

Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');
Route::get('/invitations/accept', [InvitationController::class, 'show'])->name('invitations.accept-page');
Route::post('/invitations/accept', [InvitationController::class, 'accept'])
    ->middleware('throttle:auth')
    ->name('invitations.accept');
Route::post('/invitations/accept/logout', [InvitationController::class, 'logout'])
    ->middleware('auth')
    ->name('invitations.logout');

/*
| Area bersama — bukan fungsi bisnis.
|
| Profil, keamanan akun, notifikasi, referral, level, dukungan, dan pengelolaan
| anggota usaha dipakai semua akun apa pun fungsinya. Karena itu tidak dijaga
| `area:`; yang menjaga isinya adalah izin per aksi di controller.
*/
Route::middleware('auth')->group(function (): void {
    // Beranda tunggal; isinya ditentukan tipe akun (lihat HomeController).
    Route::get('/dashboard', [HomeController::class, 'index'])->name('dashboard');

    Route::get('/profil', [AccountController::class, 'show'])->name('account.show');
    Route::patch('/profil', [AccountController::class, 'update'])->name('account.update');
    Route::patch('/profil/kata-sandi', [AccountController::class, 'updatePassword'])
        ->middleware('throttle:auth')
        ->name('account.password');
    Route::get('/profil/keamanan', [MfaController::class, 'security'])->name('account.security');
    Route::post('/profil/keamanan/dua-langkah', [MfaController::class, 'startEnable'])
        ->middleware('throttle:auth')
        ->name('account.two-factor.start');
    Route::post('/profil/keamanan/dua-langkah/konfirmasi', [MfaController::class, 'confirmEnable'])
        ->middleware('throttle:auth')
        ->name('account.two-factor.confirm');
    Route::delete('/profil/keamanan/dua-langkah', [MfaController::class, 'disable'])
        ->middleware('throttle:auth')
        ->name('account.two-factor.disable');
    Route::get('/profil/sewa', [AccountController::class, 'rentals'])->name('account.rentals');

    Route::get('/notifikasi', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifikasi/{notification}/baca', [NotificationController::class, 'read'])
        ->name('notifications.read');
    Route::post('/notifikasi/baca-semua', [NotificationController::class, 'readAll'])
        ->name('notifications.read-all');

    Route::get('/referral', [ReferralController::class, 'index'])->name('referral.index');
    Route::get('/level', [LoyaltyController::class, 'index'])->name('loyalty.index');

    Route::get('/dukungan', [SupportController::class, 'index'])->name('support.index');
    Route::get('/dukungan/buat', [SupportController::class, 'create'])->name('support.create');
    Route::post('/dukungan', [SupportController::class, 'store'])
        ->middleware('throttle:auth')
        ->name('support.store');
    Route::get('/dukungan/{ticket}', [SupportController::class, 'show'])
        ->whereNumber('ticket')
        ->name('support.show');
    Route::post('/dukungan/{ticket}/balas', [SupportController::class, 'reply'])
        ->whereNumber('ticket')
        ->name('support.reply');
    Route::post('/dukungan/{ticket}/tutup', [SupportController::class, 'close'])
        ->whereNumber('ticket')
        ->name('support.close');

    // Usaha: dilihat anggotanya, dibuat hanya oleh akun pemilik yang sesuai tipe.
    Route::post('/businesses', [BusinessController::class, 'store'])->name('businesses.store');
    Route::get('/businesses/{business}', [BusinessController::class, 'show'])->name('businesses.show');
    Route::patch('/businesses/{business}/members/{membership}', [BusinessController::class, 'updateMember'])
        ->whereNumber('membership')
        ->name('businesses.members.update');
    Route::delete('/businesses/{business}/members/{user}', [BusinessController::class, 'removeMember'])
        ->whereNumber('user')
        ->name('businesses.members.destroy');
    Route::post('/businesses/{business}/invitations', [BusinessController::class, 'invite'])
        ->name('businesses.invitations.store');
});

/*
| Area per fungsi. Satu akun hanya masuk ke areanya sendiri.
|
| Retail dan grosir berbagi rute `/mad-warung` karena keduanya memakai ruang kerja
| Mad Warung yang sama; yang membedakan adalah tipe usaha dan izin per peran.
*/
Route::middleware(['auth', 'area:retail,grosir,agency'])->group(function (): void {
    // Daftar usaha milik akun mitra — dasar untuk memilih ruang kerja.
    Route::get('/usaha', [BusinessController::class, 'index'])->name('businesses.index');

    /*
    | Melengkapi profil usaha hasil provisioning otomatis saat pendaftaran mitra.
    |
    | Jalurnya `/businesses/{business}` — sama dengan halaman detailnya — bukan
    | `/usaha/{business}`. Sebelumnya PATCH ada di `/usaha/...` sedangkan GET ada di
    | `/businesses/...`, sehingga membuka `/usaha/4` di browser menghasilkan 405
    | "GET not supported": satu sumber daya dengan dua alamat bukan hal yang wajar.
    */
    Route::patch('/businesses/{business}', [BusinessController::class, 'update'])
        ->whereNumber('business')
        ->name('businesses.update');
});

Route::middleware(['auth', 'area:retail,grosir'])->group(base_path('routes/areas/madwarung.php'));
Route::middleware(['auth', 'area:agency'])->group(base_path('routes/areas/madtrans-agency.php'));
Route::middleware(['auth', 'area:driver'])->group(base_path('routes/areas/madtrans-driver.php'));
Route::middleware(['auth', 'area:customer'])->group(base_path('routes/areas/madtrans-customer.php'));
Route::middleware(['auth', 'area:ops'])->group(base_path('routes/areas/platform.php'));

/*
| Dokumen rental dibuka dua pihak: pelanggan melihat berkasnya sendiri, agency
| memeriksa berkas penyewa sebelum kendaraan diserahkan. Karena itu rutenya
| berada di luar dua area di atas dan dijaga izin per berkas di controller.
*/
Route::middleware(['auth', 'area:customer,agency'])->group(function (): void {
    Route::get('/mad-trans/rental-documents/{document}/file', [RentalDocumentController::class, 'show'])
        ->whereNumber('document')
        ->name('rental-documents.show');
});
