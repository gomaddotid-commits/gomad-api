<?php

namespace App\Core\Rental\Application;

use App\Core\Rental\Domain\RentalStatus;
use App\Models\Rental;
use App\Models\RentalDocument;
use App\Models\User;

/**
 * Aturan akses dokumen sewa (KTP/SIM/selfie):
 * - pemilik dokumen selalu boleh melihat;
 * - admin platform memverifikasi dan boleh melihat kapan saja;
 * - agency hanya boleh melihat selama sewa berjalan (status aktif) dengan penyewa tersebut,
 *   dan akses hilang begitu sewa selesai/dibatalkan.
 */
final class RentalDocumentAccess
{
    public function isPlatformAdmin(User $user): bool
    {
        return $user->platformRoles()->where('role', 'admin')->exists();
    }

    public function canView(User $user, RentalDocument $document): bool
    {
        if ((int) $document->user_id === (int) $user->getKey()) {
            return true;
        }

        if ($this->isPlatformAdmin($user)) {
            return true;
        }

        return $this->hasRunningRentalWith((int) $user->getKey(), (int) $document->user_id);
    }

    /**
     * Agency hanya melihat dokumen saat ada sewa berjalan dengan penyewa tersebut.
     */
    private function hasRunningRentalWith(int $viewerId, int $customerId): bool
    {
        if ($viewerId === 0) {
            return false;
        }

        return Rental::query()
            ->where('customer_id', $customerId)
            ->where('status', RentalStatus::Active->value)
            ->whereHas('business', fn ($business) => $business->whereHas(
                'members',
                fn ($members) => $members
                    ->whereKey($viewerId)
                    ->where('business_members.status', 'active'),
            ))
            ->exists();
    }
}
