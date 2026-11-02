<?php

namespace App\Core\Identity\Application\Mfa;

use App\Models\TwoFactorChallenge;
use App\Models\User;
use InvalidArgumentException;

/**
 * Memeriksa kode verifikasi.
 *
 * Aturan yang dijaga: kode hanya bisa dipakai sekali, kedaluwarsa dalam 10 menit,
 * dan percobaan yang gagal dihitung — setelah 5 kali tantangan dimatikan supaya
 * tidak bisa ditebak berulang-ulang.
 */
final class VerifyTwoFactorChallenge
{
    public function execute(User $user, string $code, string $purpose): TwoFactorChallenge
    {
        $challenge = TwoFactorChallenge::query()
            ->where('user_id', $user->getKey())
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->latest('id')
            ->first();

        return $this->verify($challenge, $code);
    }

    /**
     * Dipakai klien API: tantangan dicari lewat token, bukan lewat identitas pengguna.
     */
    public function byToken(string $token, string $code): TwoFactorChallenge
    {
        $challenge = TwoFactorChallenge::query()
            ->where('token_hash', StartTwoFactorChallenge::hash($token))
            ->whereNull('consumed_at')
            ->first();

        return $this->verify($challenge, $code);
    }

    private function verify(?TwoFactorChallenge $challenge, string $code): TwoFactorChallenge
    {
        if ($challenge === null) {
            throw new InvalidArgumentException('Kode verifikasi tidak ditemukan. Minta kode baru.');
        }

        if (! $challenge->isUsable()) {
            throw new InvalidArgumentException('Kode verifikasi sudah kedaluwarsa. Minta kode baru.');
        }

        $code = trim($code);

        if (! hash_equals($challenge->code_hash, StartTwoFactorChallenge::hash($code))) {
            $challenge->forceFill(['attempts' => $challenge->attempts + 1])->save();

            if ($challenge->attempts >= TwoFactorChallenge::MAX_ATTEMPTS) {
                $challenge->forceFill(['consumed_at' => now()])->save();

                throw new InvalidArgumentException('Terlalu banyak percobaan. Minta kode baru.');
            }

            throw new InvalidArgumentException('Kode verifikasi salah.');
        }

        $challenge->forceFill(['consumed_at' => now()])->save();

        return $challenge;
    }
}
