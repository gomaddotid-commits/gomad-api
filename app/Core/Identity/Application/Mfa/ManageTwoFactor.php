<?php

namespace App\Core\Identity\Application\Mfa;

use App\Models\TwoFactorChallenge;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;

/**
 * Mengaktifkan dan mematikan verifikasi dua langkah.
 *
 * Mengaktifkan hanya boleh setelah pemilik akun membuktikan akses ke emailnya
 * (kode `enable` sudah diverifikasi). Mematikan wajib menyertakan kata sandi,
 * supaya sesi yang dibajak tidak bisa langsung melemahkan keamanan akun.
 */
final class ManageTwoFactor
{
    public function enable(User $user): User
    {
        $user->forceFill(['two_factor_enabled_at' => now()])->save();

        return $user->refresh();
    }

    public function disable(User $user, string $password): User
    {
        if (! Hash::check($password, (string) $user->password)) {
            throw new InvalidArgumentException('Kata sandi tidak sesuai.');
        }

        TwoFactorChallenge::query()
            ->where('user_id', $user->getKey())
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $user->forceFill(['two_factor_enabled_at' => null])->save();

        return $user->refresh();
    }

    /**
     * Menghanguskan kode lama sebelum kode baru dikirim.
     */
    public function invalidateChallenges(User $user): void
    {
        TwoFactorChallenge::query()
            ->where('user_id', $user->getKey())
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);
    }
}
