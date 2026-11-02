<?php

namespace App\Core\MadTrans\Application;

use App\Core\Access\Application\ActionAuthorizer;
use App\Models\Business;
use App\Models\BusinessPickupPolicy;
use App\Models\User;
use InvalidArgumentException;

/**
 * Mengatur radius jemput gratis dan tarif per kilometer di luarnya.
 *
 * Nilainya milik usaha: rupiahnya masuk ke pendapatan usaha (biaya angkut),
 * bukan fee platform. Simpan nol di keduanya berarti "tanpa biaya jemput".
 */
final class SavePickupPolicy
{
    public function __construct(private readonly ActionAuthorizer $authorizer) {}

    public function execute(User $owner, Business $business, int $freeRadiusKm, string|int|float $feePerKm): BusinessPickupPolicy
    {
        $this->authorizer->authorize($owner, 'schedule.manage', $business);

        if ($freeRadiusKm < 0 || $freeRadiusKm > 100) {
            throw new InvalidArgumentException('Radius gratis harus antara 0 dan 100 km.');
        }

        $fee = (int) round((float) $feePerKm);

        if ($fee < 0 || $fee > 1000000) {
            throw new InvalidArgumentException('Tarif per kilometer harus antara 0 dan Rp 1.000.000.');
        }

        $policy = BusinessPickupPolicy::query()->firstOrNew(['business_id' => $business->getKey()]);
        $policy->fill([
            'free_radius_km' => $freeRadiusKm,
            'fee_per_km' => $fee,
        ])->save();

        return $policy;
    }
}
