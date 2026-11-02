<?php

namespace App\Core\Referral\Application;

use App\Core\Promo\Application\CreatePromo;
use App\Core\Promo\Domain\PromoCostBearer;
use App\Models\Promo;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Pendaftaran referral.
 *
 * PR4: seluruh hadiah referral ditanggung platform. Karena itu keduanya diterbitkan
 * sebagai promo dengan `cost_bearer = platform`, bukan potongan pada agency.
 */
final class RegisterReferral
{
    public function __construct(
        private readonly CreatePromo $createPromo,
        private readonly ReferralCode $referralCode,
    ) {}

    public function execute(User $referee, string $code): Referral
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            throw new InvalidArgumentException('Kode referral tidak boleh kosong.');
        }

        $referrer = User::query()->where('referral_code', $code)->first();
        if ($referrer === null) {
            throw new InvalidArgumentException('Kode referral tidak ditemukan.');
        }

        if ((int) $referrer->getKey() === (int) $referee->getKey()) {
            throw new InvalidArgumentException('Tidak bisa memakai kode referral sendiri.');
        }

        return DB::transaction(function () use ($referrer, $referee, $code): Referral {
            // Kode milik pengguna baru selalu dibuat agar bisa dibagikan.
            $this->referralCode->for($referee);

            $existing = Referral::query()->where('referee_user_id', $referee->getKey())->first();
            if ($existing !== null) {
                return $existing;
            }

            $welcome = $this->issueWelcomePromo($referee, $referrer);

            return Referral::query()->create([
                'referrer_user_id' => $referrer->getKey(),
                'referee_user_id' => $referee->getKey(),
                'code' => $code,
                'status' => Referral::STATUS_PENDING,
                'referee_promo_id' => $welcome->getKey(),
            ]);
        }, attempts: 3);
    }

    private function issueWelcomePromo(User $referee, User $referrer): Promo
    {
        $percent = (float) config('services.referral.referee_discount_percent', 10);
        $cap = (float) config('services.referral.referee_discount_cap', 25000);

        return $this->createPromo->execute(
            $referrer,
            [
                'code' => 'WELCOME-'.$this->referralCode->for($referee),
                'name' => 'Diskon pengguna baru (referral)',
                'discount_type' => 'percent',
                'discount_value' => $percent,
                'max_discount' => $cap,
                'min_spend' => 0,
                'applies_to' => 'all',
                'usage_limit' => 1,
                'per_user_limit' => 1,
            ],
            Promo::OWNER_PLATFORM,
            PromoCostBearer::Platform,
        );
    }
}
