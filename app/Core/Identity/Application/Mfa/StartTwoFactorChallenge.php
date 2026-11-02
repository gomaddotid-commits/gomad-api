<?php

namespace App\Core\Identity\Application\Mfa;

use App\Models\TwoFactorChallenge;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Membuat tantangan verifikasi dua langkah.
 *
 * Kode 6 angka dikirim lewat kanal notifikasi (email). Yang disimpan hanya
 * HMAC-nya, jadi isi database tidak bisa dipakai untuk masuk. Tantangan lama
 * untuk tujuan yang sama langsung dihanguskan supaya tidak ada dua kode aktif.
 */
final class StartTwoFactorChallenge
{
    /**
     * @return array{code: string, token: string, challenge: TwoFactorChallenge}
     */
    public function execute(User $user, string $purpose, ?string $ip = null): array
    {
        if (! in_array($purpose, [TwoFactorChallenge::PURPOSE_LOGIN, TwoFactorChallenge::PURPOSE_ENABLE], true)) {
            throw new \InvalidArgumentException('Tujuan verifikasi tidak dikenal.');
        }

        $code = (string) random_int(100000, 999999);
        $token = Str::random(48);

        $challenge = DB::transaction(function () use ($user, $purpose, $code, $token, $ip): TwoFactorChallenge {
            TwoFactorChallenge::query()
                ->where('user_id', $user->getKey())
                ->where('purpose', $purpose)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            return TwoFactorChallenge::query()->create([
                'user_id' => $user->getKey(),
                'purpose' => $purpose,
                'channel' => 'email',
                'code_hash' => self::hash($code),
                'token_hash' => self::hash($token),
                'attempts' => 0,
                'expires_at' => now()->addMinutes(10),
                'requested_ip' => $ip,
            ]);
        });

        return ['code' => $code, 'token' => $token, 'challenge' => $challenge];
    }

    public static function hash(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }
}
