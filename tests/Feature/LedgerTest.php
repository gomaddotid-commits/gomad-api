<?php

namespace Tests\Feature;

use App\Core\Access\Infrastructure\AccessControlSeeder;
use App\Core\Ledger\Application\ConsumeWalletHold;
use App\Core\Ledger\Application\CreateWalletHold;
use App\Core\Ledger\Application\PostLedgerTransaction;
use App\Core\Ledger\Application\ReconcileLedger;
use App\Core\Ledger\Application\ReleaseWalletHold;
use App\Core\Ledger\Application\ReverseLedgerTransaction;
use App\Core\Ledger\Domain\IdempotencyConflict;
use App\Core\Ledger\Domain\LedgerCategory;
use App\Core\Ledger\Domain\LedgerDirection;
use App\Core\Ledger\Domain\LedgerEntryInput;
use App\Core\Ledger\Domain\LedgerException;
use App\Core\Ledger\Domain\LedgerMoney;
use App\Core\Ledger\Domain\PostLedgerTransactionData;
use App\Core\Ledger\Infrastructure\LedgerAccountSeeder;
use App\Models\Business;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\PlatformUserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class LedgerTest extends TestCase
{
    use RefreshDatabase;

    private LedgerAccountSeeder $accountSeeder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        $this->accountSeeder = app(LedgerAccountSeeder::class);
        $this->accountSeeder->run();
    }

    public function test_journal_entries_reconstruct_balances_and_record_balance_after(): void
    {
        $business = $this->business('agency');
        $this->accountSeeder->ensureBusinessAccounts($business);
        $this->postLedger([
            ['PLATFORM.CASH', 'debit', '160545'],
            ['BUSINESS.PAYABLE:'.$business->id, 'credit', '150000'],
            ['PLATFORM.REVENUE.SERVICE_FEE', 'credit', '5000'],
            ['PLATFORM.REVENUE.PLATFORM_FEE', 'credit', '4500'],
            ['PLATFORM.TAX_PAYABLE_PPN', 'credit', '1045'],
        ], 'booking-paid-once', LedgerCategory::BookingPaidOnline);

        $this->assertSame('150000.00', $this->account('BUSINESS.PAYABLE:'.$business->id)->balance_cached);
        $this->assertSame('160545.00', $this->account('PLATFORM.CASH')->balance_cached);
        $this->assertSame('1045.00', $this->account('PLATFORM.TAX_PAYABLE_PPN')->balance_cached);
        $this->assertSame('liability', $this->account('PLATFORM.TAX_PAYABLE_PPN')->account_class);

        $entries = LedgerTransaction::query()->firstOrFail()->entries()->orderBy('id')->get();
        $this->assertSame(
            ['160545.00', '150000.00', '5000.00', '4500.00', '1045.00'],
            $entries->pluck('balance_after')->all(),
        );
        $debits = $entries->where('direction', 'debit')->reduce(
            fn (LedgerMoney $total, $entry): LedgerMoney => $total->plus(LedgerMoney::from((string) $entry->amount)),
            LedgerMoney::zero(),
        );
        $credits = $entries->where('direction', 'credit')->reduce(
            fn (LedgerMoney $total, $entry): LedgerMoney => $total->plus(LedgerMoney::from((string) $entry->amount)),
            LedgerMoney::zero(),
        );
        $this->assertSame(0, $debits->compare($credits));

        $reconciliation = app(ReconcileLedger::class)->execute();
        $this->assertNotEmpty($reconciliation['accounts']);
        $this->assertSame([], $reconciliation['unbalanced_transactions']);
        $this->assertTrue(collect($reconciliation['accounts'])->every(fn (array $account): bool => $account['matches']));
    }

    public function test_duplicate_callback_three_times_changes_balances_once(): void
    {
        $business = $this->business('agency');
        $this->accountSeeder->ensureBusinessAccounts($business);
        $entries = [
            ['PLATFORM.CASH', 'debit', '50000'],
            ['BUSINESS.PAYABLE:'.$business->id, 'credit', '50000'],
        ];

        $first = $this->postLedger($entries, 'callback-duplicate');
        $second = $this->postLedger($entries, 'callback-duplicate');
        $third = $this->postLedger($entries, 'callback-duplicate');

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->id, $third->id);
        $this->assertDatabaseCount('ledger_transactions', 1);
        $this->assertDatabaseCount('ledger_entries', 2);
        $this->assertSame('50000.00', $this->account('PLATFORM.CASH')->balance_cached);
        $this->assertSame('50000.00', $this->account('BUSINESS.PAYABLE:'.$business->id)->balance_cached);
    }

    public function test_idempotency_key_cannot_be_reused_for_a_different_journal(): void
    {
        $business = $this->business('agency');
        $this->accountSeeder->ensureBusinessAccounts($business);
        $this->postLedger([
            ['PLATFORM.CASH', 'debit', '10000'],
            ['BUSINESS.PAYABLE:'.$business->id, 'credit', '10000'],
        ], 'same-key');

        $this->expectException(IdempotencyConflict::class);
        $this->postLedger([
            ['PLATFORM.CASH', 'debit', '20000'],
            ['BUSINESS.PAYABLE:'.$business->id, 'credit', '20000'],
        ], 'same-key');
    }

    public function test_reversal_appends_one_compensating_transaction_and_is_idempotent(): void
    {
        $business = $this->business('agency');
        $this->accountSeeder->ensureBusinessAccounts($business);
        $original = $this->postLedger([
            ['PLATFORM.CASH', 'debit', '25000'],
            ['BUSINESS.PAYABLE:'.$business->id, 'credit', '25000'],
        ], 'funds-original');
        $reverse = app(ReverseLedgerTransaction::class);

        $first = $reverse->execute($original->id, 'reverse-original');
        $second = $reverse->execute($original->id, 'reverse-original');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(2, LedgerTransaction::query()->count());
        $this->assertSame(4, LedgerEntry::query()->count());
        $this->assertSame('reversed', $original->fresh()->status);
        $this->assertSame('0.00', $this->account('PLATFORM.CASH')->balance_cached);
        $this->assertSame('0.00', $this->account('BUSINESS.PAYABLE:'.$business->id)->balance_cached);
        $reconciliation = app(ReconcileLedger::class)->execute();
        $this->assertSame([], $reconciliation['unbalanced_transactions']);
        $this->assertTrue(collect($reconciliation['accounts'])->every(
            fn (array $account): bool => $account['matches'],
        ));
    }

    public function test_ledger_entries_cannot_be_updated_or_deleted(): void
    {
        $business = $this->business('agency');
        $this->accountSeeder->ensureBusinessAccounts($business);
        $transaction = $this->postLedger([
            ['PLATFORM.CASH', 'debit', '1000'],
            ['BUSINESS.PAYABLE:'.$business->id, 'credit', '1000'],
        ], 'immutable-entry');
        $entry = $transaction->entries()->firstOrFail();

        try {
            $entry->update(['amount' => '2000.00']);
            $this->fail('Ledger entries must not be editable.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('abadi', $exception->getMessage());
        }

        $this->expectException(\LogicException::class);
        $entry->delete();
    }

    public function test_ppn_remittance_cannot_post_to_platform_revenue(): void
    {
        $this->expectException(LedgerException::class);
        $this->postLedger([
            ['PLATFORM.REVENUE.SERVICE_FEE', 'debit', '1000'],
            ['PLATFORM.CASH', 'credit', '1000'],
        ], 'invalid-ppn-remittance', LedgerCategory::PpnRemittance);
    }

    public function test_ppn_remittance_reduces_the_tax_liability_not_revenue(): void
    {
        $business = $this->business('agency');
        $this->accountSeeder->ensureBusinessAccounts($business);
        $this->postLedger([
            ['PLATFORM.CASH', 'debit', '160545'],
            ['BUSINESS.PAYABLE:'.$business->id, 'credit', '150000'],
            ['PLATFORM.REVENUE.SERVICE_FEE', 'credit', '5000'],
            ['PLATFORM.REVENUE.PLATFORM_FEE', 'credit', '4500'],
            ['PLATFORM.TAX_PAYABLE_PPN', 'credit', '1045'],
        ], 'ppn-booking-accrual', LedgerCategory::BookingPaidOnline);
        $transaction = $this->postLedger([
            ['PLATFORM.TAX_PAYABLE_PPN', 'debit', '1045'],
            ['PLATFORM.CASH', 'credit', '1045'],
        ], 'ppn-remittance', LedgerCategory::PpnRemittance);

        $this->assertSame('0.00', $this->account('PLATFORM.TAX_PAYABLE_PPN')->balance_cached);
        $this->assertSame('5000.00', $this->account('PLATFORM.REVENUE.SERVICE_FEE')->balance_cached);
        $this->assertSame('4500.00', $this->account('PLATFORM.REVENUE.PLATFORM_FEE')->balance_cached);
        $this->assertSame(
            ['PLATFORM.CASH', 'PLATFORM.TAX_PAYABLE_PPN'],
            $transaction->entries()->with('account')->get()->pluck('account.code')->sort()->values()->all(),
        );
    }

    public function test_wallet_hold_is_not_a_ledger_entry_and_cannot_be_released_twice(): void
    {
        $business = $this->business('agency');
        $this->accountSeeder->ensureBusinessAccounts($business);
        $collateralCode = 'BUSINESS.COLLATERAL:'.$business->id;
        $this->postLedger([
            ['PLATFORM.CASH', 'debit', '50000'],
            [$collateralCode, 'credit', '50000'],
        ], 'collateral-fund');
        $entryCount = LedgerEntry::query()->count();

        $hold = app(CreateWalletHold::class)->execute($collateralCode, 'schedule', 42, 'cod_collateral', '30000');
        $this->assertSame('active', $hold->status);
        $this->assertDatabaseCount('ledger_entries', $entryCount);
        try {
            app(CreateWalletHold::class)->execute($collateralCode, 'schedule', 43, 'cod_collateral', '25000');
            $this->fail('A hold larger than the free collateral balance should be rejected.');
        } catch (LedgerException $exception) {
            $this->assertStringContainsString('Saldo bebas', $exception->getMessage());
        }

        $released = app(ReleaseWalletHold::class)->execute($hold->id);
        $this->assertSame('released', $released->status);
        $this->expectException(LedgerException::class);
        app(ReleaseWalletHold::class)->execute($hold->id);
    }

    public function test_wallet_hold_can_be_consumed_by_a_balanced_ledger_transaction_once(): void
    {
        $business = $this->business('grosir');
        $account = $this->accountSeeder->ensureBusinessAccounts($business);
        $this->postLedger([
            ['PLATFORM.CASH', 'debit', '40000'],
            [$account->code, 'credit', '40000'],
        ], 'grosir-fund');
        $hold = app(CreateWalletHold::class)->execute($account->code, 'order', 17, 'ots_deposit', '15000');
        $capture = new PostLedgerTransactionData(
            category: LedgerCategory::OrderPaidToGrosir,
            idempotencyKey: 'capture-order-17',
            entries: [
                new LedgerEntryInput($account->code, LedgerDirection::Debit, '15000'),
                new LedgerEntryInput('PLATFORM.CASH', LedgerDirection::Credit, '15000'),
            ],
        );

        $consumed = app(ConsumeWalletHold::class)->execute($hold->id, $capture);
        $this->assertSame('consumed', $consumed->status);
        $this->assertNotNull($consumed->transaction_id);
        $this->assertSame('25000.00', $account->fresh()->balance_cached);
        $this->expectException(LedgerException::class);
        app(ConsumeWalletHold::class)->execute($hold->id, $capture);
    }

    public function test_only_platform_admin_can_settle_agency_or_grosir_with_an_idempotency_key(): void
    {
        $business = $this->business('grosir');
        $businessAccount = $this->accountSeeder->ensureBusinessAccounts($business);
        $agency = $this->business('agency');
        $agencyAccount = $this->accountSeeder->ensureBusinessAccounts($agency);
        $this->postLedger([
            ['PLATFORM.CASH', 'debit', '75000'],
            ['BUSINESS.PAYABLE:'.$business->id, 'credit', '45000'],
            ['BUSINESS.PAYABLE:'.$agency->id, 'credit', '30000'],
        ], 'payable-fund');
        $admin = User::factory()->create();
        PlatformUserRole::query()->create(['user_id' => $admin->id, 'role' => 'admin']);
        $token = $admin->createToken('settlement-test')->plainTextToken;

        $response = $this->withToken($token)
            ->withHeader('Idempotency-Key', 'settlement-grosir-1')
            ->postJson('/api/v1/businesses/'.$business->id.'/settlements', ['amount' => '25000'])
            ->assertCreated()
            ->assertJsonPath('data.category', 'SETTLEMENT_TO_BUSINESS')
            ->assertJsonPath('data.amount', '25000.00');

        $this->withToken($token)
            ->withHeader('Idempotency-Key', 'settlement-grosir-1')
            ->postJson('/api/v1/businesses/'.$business->id.'/settlements', ['amount' => '25000'])
            ->assertCreated()
            ->assertJsonPath('data.transaction_id', $response->json('data.transaction_id'));
        $this->withToken($token)
            ->withHeader('Idempotency-Key', 'settlement-grosir-1')
            ->postJson('/api/v1/businesses/'.$business->id.'/settlements', ['amount' => '20000'])
            ->assertConflict()
            ->assertJsonPath('code', 'IDEMPOTENCY_CONFLICT');

        $this->withToken($token)
            ->withHeader('Idempotency-Key', 'settlement-agency-1')
            ->postJson('/api/v1/businesses/'.$agency->id.'/settlements', ['amount' => '10000'])
            ->assertCreated()
            ->assertJsonPath('data.category', 'SETTLEMENT_TO_BUSINESS');

        $this->assertSame('20000.00', $this->account('BUSINESS.PAYABLE:'.$business->id)->balance_cached);
        $this->assertSame('25000.00', $businessAccount->fresh()->balance_cached);
        $this->assertSame('20000.00', $this->account('BUSINESS.PAYABLE:'.$agency->id)->balance_cached);
        $this->assertSame('10000.00', $agencyAccount->fresh()->balance_cached);
        $this->assertDatabaseCount('ledger_transactions', 3);
        $this->flushHeaders()->withToken($token)
            ->postJson('/api/v1/businesses/'.$business->id.'/settlements', ['amount' => '1000'])
            ->assertBadRequest()
            ->assertJsonPath('code', 'IDEMPOTENCY_KEY_REQUIRED');
    }

    public function test_settlement_cannot_use_another_business_payable_balance(): void
    {
        $fundedAgency = $this->business('agency');
        $targetGrosir = $this->business('grosir');
        $this->accountSeeder->ensureBusinessAccounts($fundedAgency);
        $this->accountSeeder->ensureBusinessAccounts($targetGrosir);
        $this->postLedger([
            ['PLATFORM.CASH', 'debit', '10000'],
            ['BUSINESS.PAYABLE:'.$fundedAgency->id, 'credit', '10000'],
        ], 'only-agency-payable');
        $admin = User::factory()->create();
        PlatformUserRole::query()->create(['user_id' => $admin->id, 'role' => 'admin']);
        $token = $admin->createToken('settlement-isolation')->plainTextToken;
        $nonAdminToken = User::factory()->create()->createToken('no-settlement-permission')->plainTextToken;

        $this->withToken($nonAdminToken)
            ->withHeader('Idempotency-Key', 'non-admin-settlement')
            ->postJson('/api/v1/businesses/'.$fundedAgency->id.'/settlements', ['amount' => '1'])
            ->assertForbidden()
            ->assertJsonPath('code', 'FORBIDDEN');
        Auth::forgetGuards();
        $this->flushHeaders()->withToken($token)
            ->withHeader('Idempotency-Key', 'cannot-settle-from-another-business')
            ->postJson('/api/v1/businesses/'.$targetGrosir->id.'/settlements', ['amount' => '1'])
            ->assertConflict()
            ->assertJsonPath('code', 'LEDGER_CONFLICT');
        $this->assertSame('10000.00', $this->account('BUSINESS.PAYABLE:'.$fundedAgency->id)->balance_cached);
        $this->assertSame('0.00', $this->account('BUSINESS.PAYABLE:'.$targetGrosir->id)->balance_cached);
        $this->assertDatabaseCount('ledger_transactions', 1);
    }

    public function test_settlement_rejects_an_account_balance_overdraw(): void
    {
        $business = $this->business('agency');
        $this->accountSeeder->ensureBusinessAccounts($business);
        $admin = User::factory()->create();
        PlatformUserRole::query()->create(['user_id' => $admin->id, 'role' => 'admin']);
        $token = $admin->createToken('settlement-overdraw')->plainTextToken;

        $this->withToken($token)
            ->withHeader('Idempotency-Key', 'settlement-too-large')
            ->postJson('/api/v1/businesses/'.$business->id.'/settlements', ['amount' => '1'])
            ->assertConflict()
            ->assertJsonPath('code', 'LEDGER_CONFLICT');
        $this->assertDatabaseCount('ledger_transactions', 0);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string}>  $entries
     */
    private function postLedger(
        array $entries,
        string $idempotencyKey,
        LedgerCategory $category = LedgerCategory::Adjustment,
    ): LedgerTransaction {
        $inputs = array_map(
            fn (array $entry): LedgerEntryInput => new LedgerEntryInput(
                $entry[0],
                LedgerDirection::from($entry[1]),
                $entry[2],
            ),
            $entries,
        );

        return app(PostLedgerTransaction::class)->execute(new PostLedgerTransactionData(
            category: $category,
            idempotencyKey: $idempotencyKey,
            entries: $inputs,
            description: 'Feature-test ledger posting',
        ));
    }

    private function business(string $type): Business
    {
        return Business::query()->create([
            'type' => $type,
            'name' => ucfirst($type).' ledger test',
            'code' => 'GM-'.strtoupper($type).'-'.str_pad((string) (Business::query()->count() + 1), 8, '0', STR_PAD_LEFT),
            'status' => 'active',
        ]);
    }

    private function account(string $code): LedgerAccount
    {
        return LedgerAccount::query()->where('code', $code)->firstOrFail();
    }
}
