<?php

namespace App\Core\Ledger\Application;

use App\Core\Ledger\Domain\LedgerException;
use App\Models\WalletHold;
use Illuminate\Support\Facades\DB;

final class ReleaseWalletHold
{
    public function execute(int $holdId): WalletHold
    {
        return DB::transaction(function () use ($holdId): WalletHold {
            $hold = WalletHold::query()->whereKey($holdId)->lockForUpdate()->firstOrFail();
            if ($hold->status !== 'active') {
                throw new LedgerException('Hold sudah diselesaikan dan tidak dapat dilepas kembali.');
            }
            if ($hold->expires_at !== null && $hold->expires_at->isPast()) {
                $hold->forceFill(['status' => 'expired', 'released_at' => now()])->save();

                return $hold;
            }

            $hold->forceFill(['status' => 'released', 'released_at' => now()])->save();

            return $hold;
        }, attempts: 3);
    }
}
