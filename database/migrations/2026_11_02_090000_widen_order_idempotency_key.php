<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kunci idempotensi pesanan warung disimpan sebagai `mw:` + sha256 (67 karakter),
 * sedangkan kolomnya dibuat 64 karakter. SQLite tidak menegakkan panjang varchar,
 * jadi kesalahan ini hanya muncul di MySQL/MariaDB — pesanan gagal disimpan
 * dengan "Data too long for column 'idempotency_key'".
 *
 * Kolom dilebarkan ke 128 agar konsisten dengan tabel lain (withdrawals, payments).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // Indeks unik yang sudah ada tidak disentuh; cukup lebarkan kolomnya.
            $table->string('idempotency_key', 128)->change();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('idempotency_key', 64)->change();
        });
    }
};
