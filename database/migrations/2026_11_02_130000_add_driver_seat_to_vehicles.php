<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kursi supir pada denah kendaraan.
 *
 * Kursi supir bukan kursi yang dijual, jadi ia tidak masuk `seat_layout`.
 * Kolom ini hanya menandai bahwa denah punya kursi supir di depan — dipakai
 * tampilan pelanggan dan tidak pernah ikut perhitungan kapasitas/pendapatan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table): void {
            $table->boolean('has_driver_seat')->default(false)->after('seat_layout');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table): void {
            $table->dropColumn('has_driver_seat');
        });
    }
};
