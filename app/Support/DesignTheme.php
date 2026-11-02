<?php

namespace App\Support;

use App\Core\Identity\Domain\DesignLanguage;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Penentu tema antarmuka (DS v1.0 §16.2 "Theme Selection Logic").
 *
 * Urutannya penting dan mengikuti dokumen:
 *
 *   IF product == "Public"        → consumer
 *   ELSE IF role == "Customer"    → consumer
 *   ELSE                          → operational
 *
 * Artinya tema **bukan** hanya milik peran: pemilik grosir yang membuka halaman
 * publik `/`, `/mitra`, atau formulir masuk harus melihat bahasa desain publik,
 * bukan sidebar administrasi. Sebaliknya pelanggan yang masuk ke area pesanan
 * tetap consumer karena perannya memang consumer.
 *
 * Tema tidak pernah dipakai sebagai izin (§14.3) — middleware `area:` tetap
 * satu-satunya penjaga.
 */
final class DesignTheme
{
    /**
     * Nama rute milik permukaan publik (§8.5): toko depan, pendaftaran, dan
     * penerimaan undangan. Semuanya consumer, siapa pun yang sedang masuk.
     *
     * @var list<string>
     */
    private const PUBLIC_ROUTES = [
        'home',
        'login',
        'register',
        'mfa.*',
        'invitations.*',
        'public.*',
        'warung.public.*',
    ];

    public static function for(?User $user, ?string $routeName): DesignLanguage
    {
        if ($routeName !== null && Str::is(self::PUBLIC_ROUTES, $routeName)) {
            return DesignLanguage::Consumer;
        }

        // Tamu di halaman non-publik (mis. halaman 404) tetap mendapat tema consumer
        // supaya tidak pernah jatuh ke kerangka operasional tanpa peran.
        return $user?->accountType()->designLanguage() ?? DesignLanguage::Consumer;
    }

    /**
     * Tema untuk permintaan yang sedang berjalan.
     *
     * Dipakai komponen Blade (`x-gm.card`, `x-gm.empty`) agar permukaan kaca hanya
     * muncul di tema consumer tanpa setiap view harus mengirim flag tema.
     */
    public static function current(): DesignLanguage
    {
        return self::for(auth()->user(), request()->route()?->getName());
    }
}
