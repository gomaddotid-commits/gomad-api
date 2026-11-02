<?php

namespace App\Core\Referral\Application;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * Kode referral milik pengguna, dibuat sekali dan disimpan agar bisa dibagikan.
 */
final class ReferralCode
{
    public function resolve(string $code): ?User
    {
        $code = strtoupper(trim($code));

        if ($code === '') {
            return null;
        }

        return User::query()->where('referral_code', $code)->first();
    }

    public function for(User $user): string
    {
        $existing = (string) ($user->referral_code ?? '');
        if ($existing !== '') {
            return $existing;
        }

        do {
            $code = 'GM'.strtoupper(Str::random(6));
        } while (User::query()->where('referral_code', $code)->exists());

        $user->forceFill(['referral_code' => $code])->save();

        return $code;
    }
}
