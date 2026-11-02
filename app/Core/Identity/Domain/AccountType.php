<?php

namespace App\Core\Identity\Domain;

/**
 * Satu akun hanya memiliki satu fungsi.
 *
 * Ini keputusan produk, bukan sekadar label: pemilik warung tidak boleh masuk
 * ruang kerja agency, customer tidak boleh membuka konsol ops, dan admin tidak
 * "menumpang" akun mitra. Bila satu orang butuh dua fungsi, ia membuat dua akun.
 *
 * `area()` menjadi satu-satunya sumber kebenaran untuk penjagaan rute dan menu,
 * supaya navigasi dan penolakan akses tidak pernah berbeda pendapat.
 */
enum AccountType: string
{
    case Customer = 'customer';
    case WarungOwner = 'warung_owner';
    case WarungGuard = 'warung_guard';
    case GrosirOwner = 'grosir_owner';
    case GrosirStaff = 'grosir_staff';
    case AgencyOwner = 'agency_owner';
    case AgencyStaff = 'agency_staff';
    case Driver = 'driver';
    case Ops = 'ops';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Customer => 'Pelanggan',
            self::WarungOwner => 'Pemilik warung',
            self::WarungGuard => 'Penjaga warung',
            self::GrosirOwner => 'Pemilik grosir',
            self::GrosirStaff => 'Staf grosir',
            self::AgencyOwner => 'Pemilik travel & rental',
            self::AgencyStaff => 'Staf travel & rental',
            self::Driver => 'Supir',
            self::Ops => 'Ops platform',
            self::Admin => 'Admin platform',
        };
    }

    /**
     * Area kerja yang boleh dibuka akun ini.
     *
     * `shared` selalu boleh (profil, notifikasi, bantuan) — itu bukan fungsi bisnis.
     * `public` juga selalu boleh: toko depan adalah halaman publik, bukan area peran.
     *
     * @return list<string>
     */
    public function areas(): array
    {
        return match ($this) {
            self::Customer => ['customer'],
            self::WarungOwner, self::WarungGuard => ['retail'],
            self::GrosirOwner, self::GrosirStaff => ['grosir'],
            self::AgencyOwner, self::AgencyStaff => ['agency'],
            self::Driver => ['driver'],
            self::Ops => ['ops'],
            // Admin adalah superuser platform: semua pekerjaan ops plus administrasi.
            self::Admin => ['ops', 'admin'],
        };
    }

    public function canEnter(string $area): bool
    {
        return in_array($area, $this->areas(), true);
    }

    public function isPlatform(): bool
    {
        return in_array($this, [self::Ops, self::Admin], true);
    }

    public function isMitra(): bool
    {
        return in_array($this, [
            self::WarungOwner,
            self::GrosirOwner,
            self::AgencyOwner,
        ], true);
    }

    /**
     * Tipe usaha yang boleh dibuat akun ini. Staf dan customer tidak boleh
     * membuat usaha — mereka masuk lewat undangan atau memang bukan mitra.
     */
    public function businessType(): ?string
    {
        return match ($this) {
            self::WarungOwner => 'warung',
            self::GrosirOwner => 'grosir',
            self::AgencyOwner => 'agency',
            default => null,
        };
    }

    public function homeRoute(): string
    {
        return match ($this) {
            self::WarungOwner, self::WarungGuard => 'warung.index',
            self::GrosirOwner, self::GrosirStaff => 'warung.index',
            self::AgencyOwner, self::AgencyStaff => 'mad-trans.agency.index',
            self::Driver => 'mad-trans.driver.index',
            self::Ops => 'admin.dashboard',
            self::Admin => 'admin.dashboard',
            self::Customer => 'dashboard',
        };
    }

    /**
     * Bahasa desain untuk peran ini (DS v1.0 §16.2).
     *
     * Admin, ops, seluruh mitra Mad Warung, pemilik/agen travel, dan supir
     * mengelola data operasional → Classic Minimalist. Pelanggan menjelajah dan
     * memesan → Minimalist Glassmorphism. Tamu memakai tema consumer karena yang
     * mereka lihat adalah halaman publik.
     */
    public function designLanguage(): DesignLanguage
    {
        return match ($this) {
            self::Customer => DesignLanguage::Consumer,
            default => DesignLanguage::Operational,
        };
    }

    /**
     * Pilihan yang boleh diambil saat pendaftaran mandiri.
     *
     * Staf (penjaga, supir, staf grosir/agency) tidak mendaftar sendiri — mereka
     * diundang pemilik usaha, jadi tidak muncul di formulir.
     *
     * @return list<self>
     */
    public static function selfRegistrable(): array
    {
        return [self::Customer, self::WarungOwner, self::GrosirOwner, self::AgencyOwner];
    }

    /**
     * Nilai mentah untuk validasi formulir.
     *
     * @return list<string>
     */
    public static function selfRegistrableValues(): array
    {
        return array_map(fn (self $type): string => $type->value, self::selfRegistrable());
    }

    /**
     * Tipe akun yang dipakai saat undangan usaha diterima.
     */
    public static function forMembership(string $businessType, string $role): self
    {
        return match ($businessType) {
            'warung' => $role === 'pemilik' ? self::WarungOwner : self::WarungGuard,
            'grosir' => $role === 'pemilik' ? self::GrosirOwner : self::GrosirStaff,
            'agency' => match ($role) {
                'pemilik' => self::AgencyOwner,
                'driver' => self::Driver,
                default => self::AgencyStaff,
            },
            default => self::Customer,
        };
    }
}
