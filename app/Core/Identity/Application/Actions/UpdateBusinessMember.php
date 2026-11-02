<?php

namespace App\Core\Identity\Application\Actions;

use App\Core\Access\Application\ActionAuthorizer;
use App\Models\Business;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateBusinessMember
{
    public function __construct(private readonly ActionAuthorizer $authorizer) {}

    public function execute(User $actor, Business $business, int $membershipId, string $role): void
    {
        $this->authorizer->authorize($actor, 'member.update', $business);

        $allowedRoles = match ($business->type) {
            'warung' => ['pemilik', 'penjaga', 'staf'],
            'grosir' => ['pemilik', 'staf'],
            'agency' => ['pemilik', 'driver', 'staf'],
            default => [],
        };
        if (! in_array($role, $allowedRoles, true) || $role === 'pemilik') {
            throw ValidationException::withMessages([
                'role' => ['Peran tersebut tidak dapat ditetapkan kepada staff.'],
            ]);
        }

        DB::transaction(function () use ($business, $membershipId, $role): void {
            $membership = DB::table('business_members')
                ->where('id', $membershipId)
                ->where('business_id', $business->getKey())
                ->lockForUpdate()
                ->first();

            if ($membership === null || $membership->status !== 'active') {
                throw ValidationException::withMessages([
                    'membership' => ['Keanggotaan tidak ditemukan atau tidak aktif.'],
                ]);
            }

            if ((int) $membership->user_id === (int) $business->owner_user_id || $membership->role === 'pemilik') {
                throw ValidationException::withMessages([
                    'membership' => ['Peran pemilik tidak dapat diubah melalui pengelolaan staff.'],
                ]);
            }

            if ($membership->role === $role) {
                return;
            }

            if ($business->members()
                ->whereKey($membership->user_id)
                ->wherePivot('role', $role)
                ->exists()) {
                throw ValidationException::withMessages([
                    'role' => ['Peran tersebut sudah tercatat untuk pengguna ini di usaha ini.'],
                ]);
            }

            DB::table('business_members')
                ->where('id', $membershipId)
                ->update(['role' => $role, 'updated_at' => now()]);
        });
    }
}
