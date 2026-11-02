<?php

namespace App\Core\Identity\Domain;

/**
 * Design language yang melekat pada sebuah peran (DS v1.0 §2.2, §14.1).
 *
 * Pemilihan bahasa desain mengikuti **aktivitas** pengguna, bukan nama produk:
 * pemilik usaha mengelola data (Classic Minimalist), pelanggan menjelajah dan
 * memesan (Minimalist Glassmorphism). Karena itu keputusannya hidup di domain
 * peran, bukan di dalam Blade — supaya layout, navigasi, dan komponen tidak
 * pernah berbeda pendapat tentang tema yang dipakai.
 *
 * Tema **bukan** mekanisme keamanan (DS §14.3). Otorisasi tetap di middleware.
 */
enum DesignLanguage: string
{
    case Operational = 'operational';
    case Consumer = 'consumer';

    public function label(): string
    {
        return match ($this) {
            self::Operational => 'Classic Minimalist',
            self::Consumer => 'Fresh & Energetic Minimalist Glassmorphism',
        };
    }

    /**
     * Benar bila permukaan kaca (glassmorphism) boleh dipakai (DS §8.4).
     */
    public function allowsGlass(): bool
    {
        return $this === self::Consumer;
    }
}
