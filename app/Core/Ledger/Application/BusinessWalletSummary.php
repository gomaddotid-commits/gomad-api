<?php

namespace App\Core\Ledger\Application;

use App\Models\Business;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ringkasan dompet sebuah usaha: tiga saldo yang dipakai di layar agency,
 * saldo jaminan, mutasi terakhir, dan dana yang sedang ditahan.
 *
 * Dipakai bersama oleh portal web dan API supaya angka yang dilihat pemilik
 * di layar tidak pernah berbeda dengan yang dilihat aplikasi.
 */
class BusinessWalletSummary
{
    /**
     * @return list<string>
     */
    public function accountCodes(Business $business): array
    {
        $id = $business->getKey();

        return [
            'BUSINESS.AVAILABLE:'.$id,
            'BUSINESS.COLLATERAL:'.$id,
            'BUSINESS.PAYABLE:'.$id,
        ];
    }

    /**
     * @return array{available: string, collateral: string, payable: string}
     */
    public function balances(Business $business): array
    {
        return [
            'available' => $this->balance($business, 'AVAILABLE'),
            'collateral' => $this->balance($business, 'COLLATERAL'),
            'payable' => $this->balance($business, 'PAYABLE'),
        ];
    }

    public function balance(Business $business, string $kind): string
    {
        return (string) (LedgerAccount::query()
            ->where('code', 'BUSINESS.'.$kind.':'.$business->getKey())
            ->value('balance_cached') ?? '0.00');
    }

    /**
     * @return Collection<int, LedgerEntry>
     */
    public function mutations(Business $business, int $limit = 50): Collection
    {
        return LedgerEntry::query()
            ->with(['transaction:id,category,description,occurred_at,reference_type,reference_id'])
            ->whereIn('account_id', $this->accountIds($business))
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, object>
     */
    public function activeHolds(Business $business): Collection
    {
        return DB::table('wallet_holds')
            ->whereIn('account_id', $this->accountIds($business))
            ->where('status', 'active')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @return Collection<int, int>
     */
    private function accountIds(Business $business): Collection
    {
        return LedgerAccount::query()
            ->whereIn('code', $this->accountCodes($business))
            ->pluck('id');
    }
}
