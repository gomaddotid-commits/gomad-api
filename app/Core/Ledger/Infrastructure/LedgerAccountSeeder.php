<?php

namespace App\Core\Ledger\Infrastructure;

use App\Core\Ledger\Domain\AccountClass;
use App\Models\Business;
use App\Models\LedgerAccount;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LedgerAccountSeeder extends Seeder
{
    private const PLATFORM_ACCOUNTS = [
        'PLATFORM.CASH' => ['asset', 'cash'],
        'PLATFORM.WITHDRAWAL_PAYABLE' => ['liability', 'withdrawal_payable'],
        'PLATFORM.DEPOSIT_PAYABLE' => ['liability', 'deposit_payable'],
        'PLATFORM.REVENUE.SERVICE_FEE' => ['revenue', 'service_fee'],
        'PLATFORM.REVENUE.PLATFORM_FEE' => ['revenue', 'platform_fee'],
        'PLATFORM.REVENUE.WITHDRAWAL_FEE' => ['revenue', 'withdrawal_fee'],
        'PLATFORM.TAX_PAYABLE_PPN' => ['liability', 'tax_payable_ppn'],
        'PLATFORM.EXPENSE.PROMO' => ['expense', 'promo'],
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            foreach (self::PLATFORM_ACCOUNTS as $code => [$accountClass, $purpose]) {
                $this->ensureAccount($code, 'platform', null, $accountClass, $purpose);
            }

            Business::query()
                ->whereIn('type', ['agency', 'grosir'])
                ->orderBy('id')
                ->each(function (Business $business): void {
                    $this->ensureBusinessAccounts($business);
                });
        });
    }

    public function ensureBusinessAccounts(Business $business): LedgerAccount
    {
        if (! in_array($business->type, ['agency', 'grosir'], true)) {
            throw new \InvalidArgumentException('Akun ledger hanya tersedia untuk agency dan grosir.');
        }

        return DB::transaction(function () use ($business): LedgerAccount {
            $account = $this->ensureAccount(
                'BUSINESS.AVAILABLE:'.$business->getKey(),
                'business',
                (int) $business->getKey(),
                AccountClass::Liability->value,
                'available',
            );
            $this->ensureAccount(
                'BUSINESS.PAYABLE:'.$business->getKey(),
                'business',
                (int) $business->getKey(),
                AccountClass::Liability->value,
                'payable',
            );

            if ($business->type === 'agency') {
                $this->ensureAccount(
                    'BUSINESS.COLLATERAL:'.$business->getKey(),
                    'business',
                    (int) $business->getKey(),
                    AccountClass::Liability->value,
                    'collateral',
                );
            }

            return $account;
        });
    }

    private function ensureAccount(
        string $code,
        string $ownerType,
        ?int $ownerId,
        string $accountClass,
        string $purpose,
    ): LedgerAccount {
        $account = LedgerAccount::query()->firstOrCreate(
            ['code' => $code],
            [
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
                'account_class' => $accountClass,
                'purpose' => $purpose,
                'balance_cached' => '0.00',
                'currency' => 'IDR',
                'is_active' => true,
            ],
        );

        if ($account->owner_type !== $ownerType
            || (int) $account->owner_id !== (int) $ownerId
            || $account->account_class !== $accountClass
            || $account->purpose !== $purpose
            || $account->currency !== 'IDR') {
            throw new \LogicException('Konfigurasi akun ledger yang sudah ada tidak sesuai: '.$code);
        }

        return $account;
    }
}
