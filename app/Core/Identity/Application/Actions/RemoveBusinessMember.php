<?php

namespace App\Core\Identity\Application\Actions;

use App\Core\Access\Application\ActionAuthorizer;
use App\Models\Business;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RemoveBusinessMember
{
    public function __construct(private readonly ActionAuthorizer $authorizer) {}

    public function execute(User $actor, Business $business, User $member): void
    {
        $this->authorizer->authorize($actor, 'member.remove', $business);

        if ((int) $business->owner_user_id === (int) $member->getKey()
            || $business->members()->whereKey($member->getKey())->wherePivot('role', 'pemilik')->exists()) {
            throw ValidationException::withMessages([
                'member' => ['Pemilik usaha tidak dapat dikeluarkan dari usahanya.'],
            ]);
        }

        $updated = DB::table('business_members')
            ->where('business_id', $business->getKey())
            ->where('user_id', $member->getKey())
            ->where('status', 'active')
            ->update(['status' => 'inactive', 'left_at' => now(), 'updated_at' => now()]);

        if ($updated === 0) {
            throw ValidationException::withMessages([
                'member' => ['Anggota aktif tidak ditemukan di usaha ini.'],
            ]);
        }
    }
}
