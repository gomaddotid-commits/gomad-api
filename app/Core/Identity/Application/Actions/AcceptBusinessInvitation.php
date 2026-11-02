<?php

namespace App\Core\Identity\Application\Actions;

use App\Core\Identity\Domain\AccountType;
use App\Models\Business;
use App\Models\BusinessInvitation;
use App\Models\BusinessMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AcceptBusinessInvitation
{
    /** @return array{business: Business, role: string, user: User} */
    public function execute(
        string $token,
        ?User $authenticatedUser,
        ?string $name,
        ?string $password,
        ?string $email,
    ): array {
        return DB::transaction(function () use ($token, $authenticatedUser, $name, $password, $email): array {
            $invitation = BusinessInvitation::query()
                ->where('token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->first();

            if ($invitation === null
                || $invitation->accepted_at !== null
                || $invitation->revoked_at !== null
                || $invitation->expires_at->isPast()) {
                throw ValidationException::withMessages([
                    'invitation' => ['Undangan tidak valid atau sudah kedaluwarsa.'],
                ]);
            }

            $existingUser = User::query()->where('phone', $invitation->phone)->lockForUpdate()->first();

            $requiredType = AccountType::forMembership(
                (string) $invitation->business->type,
                (string) $invitation->role,
            );

            if ($existingUser !== null) {
                if ($authenticatedUser === null || $authenticatedUser->isNot($existingUser)) {
                    throw ValidationException::withMessages([
                        'identity' => ['Masuk dengan akun yang menerima undangan untuk melanjutkan.'],
                    ]);
                }

                // Satu akun satu fungsi: akun pelanggan tidak bisa berubah menjadi staf
                // hanya karena menerima undangan. Pemilik harus mengundang nomor lain.
                if ($existingUser->accountType() !== $requiredType) {
                    throw ValidationException::withMessages([
                        'account' => ['Nomor ini sudah dipakai akun '.$existingUser->accountType()->label().'. Undangan staf memerlukan akun baru dengan nomor lain.'],
                    ]);
                }

                $user = $existingUser;
            } else {
                if ($authenticatedUser !== null || $name === null || $password === null) {
                    throw ValidationException::withMessages([
                        'account' => ['Lengkapi nama dan kata sandi untuk membuat akun penerima undangan.'],
                    ]);
                }

                $user = User::query()->create([
                    'name' => trim($name),
                    'phone' => $invitation->phone,
                    'email' => $email === null ? null : mb_strtolower(trim($email)),
                    'password' => $password,
                    'status' => 'active',
                    'account_type' => $requiredType->value,
                ]);
            }

            if ($user->status !== 'active') {
                throw ValidationException::withMessages([
                    'identity' => ['Akun ini tidak dapat menerima undangan.'],
                ]);
            }

            /*
            | Satu penjaga, satu warung.
            |
            | Penjaga berdiri di belakang satu meja kasir; kalau ia boleh tersebar di
            | beberapa warung, uang yang ia pegang dan laporan yang ia lihat menjadi
            | kabur. Pemilik justru sebaliknya: boleh punya beberapa warung dan
            | berpindah di antara mereka. Karena itu aturannya melekat pada peran
            | `penjaga`, bukan pada tipe akun.
            */
            if ($invitation->role === 'penjaga') {
                $otherWarung = BusinessMember::query()
                    ->where('user_id', $user->getKey())
                    ->where('role', 'penjaga')
                    ->where('status', 'active')
                    ->where('business_id', '!=', $invitation->business_id)
                    ->exists();

                if ($otherWarung) {
                    throw ValidationException::withMessages([
                        'invitation' => ['Akun penjaga hanya boleh menjadi anggota satu warung. Keluarkan dulu dari warung sebelumnya.'],
                    ]);
                }
            }

            $membership = $invitation->business->members()
                ->where('users.id', $user->getKey())
                ->wherePivot('role', $invitation->role)
                ->first();

            if ($membership?->pivot->status === 'active') {
                throw ValidationException::withMessages([
                    'invitation' => ['Akun ini sudah menjadi anggota usaha.'],
                ]);
            }

            if ($membership !== null) {
                $membership->pivot->forceFill([
                    'status' => 'active',
                    'joined_at' => now(),
                    'left_at' => null,
                ])->save();
            } else {
                $invitation->business->members()->attach($user->getKey(), [
                    'role' => $invitation->role,
                    'status' => 'active',
                    'joined_at' => now(),
                ]);
            }

            $invitation->forceFill(['accepted_at' => now()])->save();

            return [
                'business' => $invitation->business,
                'role' => $invitation->role,
                'user' => $user,
            ];
        });
    }
}
