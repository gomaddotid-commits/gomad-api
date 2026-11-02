<?php

namespace App\Core\Identity\Application\Actions;

use App\Core\Access\Application\ActionAuthorizer;
use App\Models\Business;
use App\Models\User;

/**
 * Verifikasi mitra oleh admin platform.
 *
 * Sebelum diverifikasi, mitra tetap bisa menyiapkan data tetapi belum boleh
 * melayani transaksi. Verifikasi menstempel `verified_at` dan — bila masih draf —
 * mengaktifkan usahanya, sehingga satu tindakan admin menyelesaikan keduanya.
 *
 * Pencabutan verifikasi hanya menghapus stempel; status usaha tidak diturunkan
 * diam-diam karena bisa memutus transaksi yang sedang berjalan.
 */
final class VerifyBusiness
{
    public function __construct(private readonly ActionAuthorizer $authorizer) {}

    public function execute(User $actor, Business $business, bool $verified = true): Business
    {
        $this->authorizer->authorize($actor, 'business.verify');

        $attributes = ['verified_at' => $verified ? now() : null];

        if ($verified && $business->status === 'draft') {
            $attributes['status'] = 'active';
        }

        $business->forceFill($attributes)->save();

        return $business->refresh();
    }
}
