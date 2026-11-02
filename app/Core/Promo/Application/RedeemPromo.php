<?php

namespace App\Core\Promo\Application;

use App\Models\Promo;
use App\Models\PromoRedemption;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Mencatat pemakaian promo. Dijalankan di dalam transaksi pemesanan agar
 * kuota dan batas per pengguna tidak bisa dilewati oleh permintaan bersamaan.
 */
final class RedeemPromo
{
    public function execute(
        PromoQuote $quote,
        User $customer,
        string $referenceType,
        int $referenceId,
    ): PromoRedemption {
        return DB::transaction(function () use ($quote, $customer, $referenceType, $referenceId): PromoRedemption {
            $promo = Promo::query()->whereKey($quote->promo->getKey())->lockForUpdate()->firstOrFail();

            if ($promo->usage_limit !== null && $promo->used_count >= $promo->usage_limit) {
                throw new InvalidArgumentException('Kuota kode promo sudah habis.');
            }

            $usedByCustomer = PromoRedemption::query()
                ->where('promo_id', $promo->getKey())
                ->where('user_id', $customer->getKey())
                ->count();
            if ($usedByCustomer >= $promo->per_user_limit) {
                throw new InvalidArgumentException('Kode promo ini sudah pernah Anda pakai.');
            }

            $redemption = PromoRedemption::query()->create([
                'promo_id' => $promo->getKey(),
                'user_id' => $customer->getKey(),
                'business_id' => $quote->businessId,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'discount_amount' => $quote->discount->toDecimal(),
                'cost_bearer' => $quote->bearer->value,
            ]);

            $promo->forceFill(['used_count' => $promo->used_count + 1])->save();

            return $redemption;
        }, attempts: 3);
    }
}
