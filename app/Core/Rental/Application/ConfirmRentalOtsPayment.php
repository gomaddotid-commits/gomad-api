<?php

namespace App\Core\Rental\Application;

use App\Core\Access\Application\ActionAuthorizer;
use App\Core\Ledger\Application\PostLedgerTransaction;
use App\Core\Ledger\Application\ReleaseWalletHold;
use App\Core\Ledger\Domain\LedgerCategory;
use App\Core\Ledger\Domain\LedgerDirection;
use App\Core\Ledger\Domain\LedgerEntryInput;
use App\Core\Ledger\Domain\LedgerMoney;
use App\Core\Ledger\Domain\PostLedgerTransactionData;
use App\Core\Payment\Domain\PaymentConflict;
use App\Core\Rental\Domain\RentalStatus;
use App\Models\LedgerTransaction;
use App\Models\Rental;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ConfirmRentalOtsPayment
{
    public function __construct(
        private readonly ActionAuthorizer $authorizer,
        private readonly ReleaseWalletHold $releaseHold,
        private readonly PostLedgerTransaction $postTransaction,
    ) {}

    /**
     * Sewa bayar di tempat (OTS): agency menagih tunai saat serah terima, lalu
     * fee platform diambil dari jaminan yang sudah ditahan sejak pemesanan.
     */
    public function execute(User $actor, Rental $rental): LedgerTransaction
    {
        $this->authorizer->authorize($actor, 'schedule.manage', $rental->business);

        return DB::transaction(function () use ($rental, $actor): LedgerTransaction {
            $locked = Rental::query()->whereKey($rental->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->payment_mode !== 'ots') {
                throw new InvalidArgumentException('Sewa ini bukan bayar di tempat; gunakan pencatatan pembayaran biasa.');
            }
            if ($locked->status !== RentalStatus::Pending->value) {
                throw new PaymentConflict('Sewa tidak lagi menunggu pembayaran.');
            }

            $hold = DB::table('wallet_holds')
                ->where('source_type', 'rental')
                ->where('source_id', $locked->getKey())
                ->where('reason', 'ots_deposit')
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if ($hold === null) {
                throw new PaymentConflict('Jaminan OTS untuk sewa ini tidak tersedia.');
            }

            $feeAmount = LedgerMoney::from((string) $locked->service_fee)
                ->plus(LedgerMoney::from((string) $locked->platform_fee))
                ->plus(LedgerMoney::from((string) $locked->ppn_amount));

            if (LedgerMoney::from((string) $hold->amount)->compare($feeAmount) < 0) {
                throw new PaymentConflict('Jaminan OTS lebih kecil dari fee sewa; perlu rekonsiliasi.');
            }

            $this->releaseHold->execute((int) $hold->id);

            $entries = [
                new LedgerEntryInput(
                    'BUSINESS.COLLATERAL:'.$locked->business_id,
                    LedgerDirection::Debit,
                    $feeAmount->toDecimal(),
                ),
            ];

            foreach ([
                'service_fee' => 'PLATFORM.REVENUE.SERVICE_FEE',
                'platform_fee' => 'PLATFORM.REVENUE.PLATFORM_FEE',
                'ppn_amount' => 'PLATFORM.TAX_PAYABLE_PPN',
            ] as $field => $accountCode) {
                $amount = LedgerMoney::from((string) $locked->{$field});
                if ($amount->isPositive()) {
                    $entries[] = new LedgerEntryInput($accountCode, LedgerDirection::Credit, $amount->toDecimal());
                }
            }

            $transaction = $this->postTransaction->execute(new PostLedgerTransactionData(
                category: LedgerCategory::OtsFeeCollected,
                idempotencyKey: 'rental:ots:'.$locked->getKey(),
                entries: $entries,
                referenceType: 'rental',
                referenceId: (int) $locked->getKey(),
                description: 'Fee OTS sewa Mad Trans '.$locked->code,
                createdBy: (int) $actor->getKey(),
            ));

            $locked->forceFill([
                'status' => RentalStatus::Paid->value,
                'paid_at' => now(),
                'ots_fee_transaction_id' => $transaction->getKey(),
                'deposit_status' => (float) $locked->deposit_amount > 0 ? 'held' : 'none',
            ])->save();

            return $transaction;
        }, attempts: 3);
    }
}
