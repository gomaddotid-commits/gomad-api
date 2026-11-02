<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kebijakan radius jemput per usaha.
 *
 * Door-to-door: pelanggan boleh meminta dijemput di luar titik kumpul. Sampai
 * radius tertentu gratis; selebihnya dihitung per kilometer dan **masuk ke
 * pendapatan usaha** (biaya operasional angkut), bukan fee platform.
 *
 * Tabel dipisah dari `businesses` supaya tabel usaha tetap netral: Mad Warung
 * nanti bisa memakai kebijakan yang sama untuk radius pengiriman.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_pickup_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('free_radius_km')->default(0);
            $table->decimal('fee_per_km', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::table('route_stops', function (Blueprint $table): void {
            $table->decimal('latitude', 10, 7)->nullable()->after('pickup_zone');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->decimal('pickup_distance_km', 8, 2)->nullable()->after('pickup_longitude');
            $table->decimal('pickup_fee', 18, 2)->default(0)->after('pickup_distance_km');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn(['pickup_distance_km', 'pickup_fee']);
        });

        Schema::table('route_stops', function (Blueprint $table): void {
            $table->dropColumn(['latitude', 'longitude']);
        });

        Schema::dropIfExists('business_pickup_policies');
    }
};
