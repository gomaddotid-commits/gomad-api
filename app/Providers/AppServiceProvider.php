<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));

        // Jalur yang menyentuh uang: dibatasi per pengguna (fallback ke IP untuk tamu).
        RateLimiter::for('money', fn (Request $request) => Limit::perMinute(30)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));

        // Unggah berkas (dokumen sewa) dan pembuatan promo.
        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(20)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));

        RateLimiter::for('promo', fn (Request $request) => Limit::perMinute(10)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));

        // Pencarian publik: tanpa akun, jadi dibatasi per IP agar tidak menjadi
        // alat pembebani database (setiap pencarian menyentuh jadwal + kursi).
        RateLimiter::for('public-search', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));
    }
}
