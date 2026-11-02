<?php

namespace App\Core\Ledger\Application;

use App\Core\Ledger\Domain\IdempotencyConflict;
use App\Core\Ledger\Domain\LedgerException;
use App\Core\Ledger\Domain\LedgerMoney;
use App\Models\LedgerAccount;
use App\Models\WalletHold;
use Illuminate\Support\Facades\DB;

final class CreateWalletHold
{
    public function execute(
        string $accountCode,
        string $sourceType,
        int $sourceId,
        string $reason,
        string|int $amount,
        ?\DateTimeInterface $expiresAt = null,
    ): WalletHold {
        $money = LedgerMoney::from($amount);
        if (! $money->isPositive()) {
            throw new \InvalidArgumentException('Nominal hold harus lebih besar dari nol.');
        }
        if ($sourceType === '' || strlen($sourceType) > 50 || $sourceId < 1 || $reason === '' || strlen($reason) > 50) {
            throw new \InvalidArgumentException('Identitas sumber hold tidak valid.');
        }

        return DB::transaction(function () use (
            $accountCode,
            $sourceType,
            $sourceId,
            $reason,
            $money,
            $expiresAt,
        ): WalletHold {
            $account = LedgerAccount::query()
                ->where('code', $accountCode)
                ->lockForUpdate()
                ->firstOrFail();
            if (! $account->is_active || $account->account_class !== 'liability') {
                throw new LedgerException('Hold hanya dapat dibuat pada akun kewajiban aktif.');
            }

            $existing = WalletHold::query()
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->where('reason', $reason)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                if ((int) $existing->account_id !== (int) $account->getKey()
                    || LedgerMoney::from((string) $existing->amount)->compare($money) !== 0) {
                    throw new IdempotencyConflict('Sumber hold sudah digunakan dengan nominal atau akun berbeda.');
                }

                return $existing;
            }

            $now = now();
            WalletHold::query()
                ->where('account_id', $account->getKey())
                ->where('status', 'active')
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', $now)
                ->update(['status' => 'expired', 'released_at' => $now, 'updated_at' => $now]);

            $held = $account->holds()
                ->where('status', 'active')
                ->lockForUpdate()
                ->get()
                ->reduce(
                    fn (LedgerMoney $sum, WalletHold $hold): LedgerMoney => $sum->plus(
                        LedgerMoney::from((string) $hold->amount),
                    ),
                    LedgerMoney::zero(),
                );
            $balance = LedgerMoney::from((string) $account->balance_cached);
            $available = $balance->minus($held);
            if ($available->compare($money) < 0) {
                throw new LedgerException('Saldo bebas tidak mencukupi untuk membuat hold.');
            }

            return $account->holds()->create([
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'reason' => $reason,
                'amount' => $money->toDecimal(),
                'status' => 'active',
                'expires_at' => $expiresAt,
            ]);
        }, attempts: 3);
    }
}
