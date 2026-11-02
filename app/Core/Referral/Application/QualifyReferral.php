<?php

namespace App\Core\Referral\Application;

use App\Core\Loyalty\Application\AwardLoyaltyPoints;
use App\Core\Promo\Application\CreatePromo;
use App\Core\Promo\Domain\PromoCostBearer;
use App\Models\LoyaltyEvent;
use App\Models\Promo;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Menyelesaikan referral saat pengundang menyelesaikan transaksi berbayar pertamanya.
 * Hadiah pengundang juga berupa promo platform (PR4), sehingga tidak ada agency yang
 * bagiannya berkurang karena referral.
 */
final class QualifyReferral
{
    public function __construct(
        private readonly CreatePromo $createPromo,
        private readonly ReferralCode $referralCode,
        private readonly AwardLoyaltyPoints $awardPoints,
    ) {}

    public function execute(User $referee): ?Referral
    {
        try {
            return DB::transaction(function () use ($referee): ?Referral {
                $referral = Referral::query()
                    ->where('referee_user_id', $referee->getKey())
                    ->whereIn('status', [Referral::STATUS_PENDING, Referral::STATUS_QUALIFIED])
                    ->lockForUpdate()
                    ->first();

                if ($referral === null || $referral->status === Referral::STATUS_REWARDED) {
                    return $referral;
                }

                if ($referral->status === Referral::STATUS_PENDING) {
                    $referral->forceFill([
                        'status' => Referral::STATUS_QUALIFIED,
                        'qualified_at' => now(),
                    ])->save();
                }

                $referrer = User::query()->find($referral->referrer_user_id);
                if ($referrer === null) {
                    return $referral;
                }

                $reward = $this->createPromo->execute(
                    $referrer,
                    [
                        'code' => 'REW-'.$this->referralCode->for($referrer),
                        'name' => 'Hadiah referral',
                        'discount_type' => 'amount',
                        'discount_value' => (float) config('services.referral.referrer_reward', 25000),
                        'min_spend' => 0,
                        'applies_to' => 'all',
                        'usage_limit' => 1,
                        'per_user_limit' => 1,
                    ],
                    Promo::OWNER_PLATFORM,
                    PromoCostBearer::Platform,
                );

                $referral->forceFill([
                    'status' => Referral::STATUS_REWARDED,
                    'referrer_promo_id' => $reward->getKey(),
                    'rewarded_at' => now(),
                ])->save();

                $this->awardPoints->execute(
                    $referrer,
                    LoyaltyEvent::KIND_REFERRAL,
                    'referral',
                    (int) $referral->getKey(),
                    0,
                    (int) config('services.referral.referrer_points', 25),
                );

                return $referral;
            }, attempts: 3);
        } catch (\Throwable $exception) {
            // Referral tidak boleh menggagalkan pembayaran yang sudah sah.
            Log::warning('Referral tidak dapat diselesaikan otomatis.', [
                'referee_user_id' => $referee->getKey(),
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }
}
