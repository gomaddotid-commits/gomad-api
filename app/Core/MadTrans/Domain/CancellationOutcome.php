<?php

namespace App\Core\MadTrans\Domain;

/**
 * Hasil keputusan "boleh dibatalkan atau tidak" untuk satu pemesanan.
 *
 * Dipakai dua tempat: layar konfirmasi (sebelum pengguna menekan tombol) dan
 * use case pembatalan itu sendiri. Karena keduanya membaca objek yang sama,
 * layar tidak bisa menjanjikan sesuatu yang kemudian ditolak oleh server.
 */
final readonly class CancellationOutcome
{
    public function __construct(
        public bool $allowed,
        /** Kode mesin: `pending_online`, `cod_unboarded`, atau `blocked`. */
        public string $code,
        /** Kalimat yang aman ditampilkan apa adanya ke pengguna. */
        public string $reason,
        /** Pembatalan melepas jaminan kursi COD (hold) — bukan uang yang sudah dibayar. */
        public bool $releasesCodHold = false,
        /** Dana sudah masuk ke platform; pembatalan otomatis tidak mengembalikannya. */
        public bool $moneyCaptured = false,
    ) {}

    public static function blocked(string $reason, bool $moneyCaptured = false): self
    {
        return new self(false, 'blocked', $reason, false, $moneyCaptured);
    }

    /**
     * @return array{allowed: bool, code: string, reason: string, releases_cod_hold: bool, money_captured: bool}
     */
    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'code' => $this->code,
            'reason' => $this->reason,
            'releases_cod_hold' => $this->releasesCodHold,
            'money_captured' => $this->moneyCaptured,
        ];
    }
}
