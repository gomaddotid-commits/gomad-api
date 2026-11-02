<?php

namespace App\Core\MadTrans\Application;

use App\Core\MadTrans\Domain\GeoPoint;
use App\Core\MadTrans\Domain\PickupSurcharge;
use App\Models\BusinessPickupPolicy;

/**
 * Menghitung biaya jemput di luar titik kumpul.
 *
 * Aturan (satu tempat, dipakai web, API, dan pembuatan booking):
 *  - jarak dihitung dari titik kumpul yang dipilih ke titik jemput pelanggan;
 *  - sampai `free_radius_km` gratis;
 *  - selebihnya dibulatkan **ke atas per kilometer** lalu dikali `fee_per_km`;
 *  - tanpa kebijakan, tanpa titik kumpul, atau tanpa titik jemput → tanpa biaya.
 *
 * Angka yang dipakai selalu hasil hitung server; jarak yang dikirim klien tidak
 * pernah dipercaya.
 */
final class ComputePickupSurcharge
{
    public function execute(
        ?BusinessPickupPolicy $policy,
        ?GeoPoint $pickupStop,
        ?GeoPoint $customerPoint,
    ): PickupSurcharge {
        if ($policy === null || $pickupStop === null || $customerPoint === null) {
            return PickupSurcharge::none();
        }

        $distanceKm = $pickupStop->distanceKmTo($customerPoint);
        $freeRadiusKm = max(0, (int) $policy->free_radius_km);
        $feePerKm = (int) round((float) $policy->fee_per_km);

        $chargeable = $distanceKm - $freeRadiusKm;

        if ($chargeable <= 0 || $feePerKm <= 0) {
            return new PickupSurcharge($distanceKm, $freeRadiusKm, 0, 0);
        }

        $billableKm = (int) ceil($chargeable);

        return new PickupSurcharge($distanceKm, $freeRadiusKm, $billableKm, $billableKm * $feePerKm);
    }
}
