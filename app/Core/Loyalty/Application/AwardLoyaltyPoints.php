<?php

namespace App\Core\Loyalty\Application;

use App\Models\LoyaltyEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Poin loyalitas dari transaksi yang benar-benar lunas.
 *
 * Poin dihitung dari nilai yang dibayar pembeli ke penjual (sebelum diskon promo
 * platform), lalu disimpan idempoten per transaksi.
 */
final class AwardLoyaltyPoints
{
    public function execute(
        User $user,
        string $kind,
        string $referenceType,
        int $referenceId,
        int $baseCents,
        ?int $fixedPoints = null,
    ): ?LoyaltyEvent {
        $perAmount = max(1000, (int) config('services.loyalty.points_per_amount', 10000));
        $points = $fixedPoints ?? intdiv(max(0, $baseCents), $perAmount * 100);

        if ($points < 1) {
            return null;
        }

        return DB::transaction(function () use ($user, $kind, $referenceType, $referenceId, $points): ?LoyaltyEvent {
            $existing = LoyaltyEvent::query()
                ->where('kind', $kind)
                ->where('reference_type', $referenceType)
                ->where('reference_id', $referenceId)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $event = LoyaltyEvent::query()->create([
                'user_id' => $user->getKey(),
                'kind' => $kind,
                'points' => $points,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'occurred_at' => now(),
            ]);

            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->first();
            if ($locked !== null) {
                $locked->forceFill(['points_cached' => (int) $locked->points_cached + $points])->save();
            }

            return $event;
        }, attempts: 3);
    }
}
