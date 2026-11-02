<?php

namespace Tests\Feature;

use App\Core\Access\Infrastructure\AccessControlSeeder;
use App\Core\Identity\Domain\AccountType;
use App\Core\Ledger\Application\PostLedgerTransaction;
use App\Core\Ledger\Domain\LedgerCategory;
use App\Core\Ledger\Domain\LedgerDirection;
use App\Core\Ledger\Domain\LedgerEntryInput;
use App\Core\Ledger\Domain\PostLedgerTransactionData;
use App\Core\Ledger\Infrastructure\LedgerAccountSeeder;
use App\Core\Notification\Application\WithdrawalStatusNotification;
use App\Models\Business;
use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use App\Models\PlatformUserRole;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class WithdrawalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        app(LedgerAccountSeeder::class)->run();
    }

    public function test_owner_can_request_an_idempotent_withdrawal_and_only_sees_a_masked_account_number(): void
    {
        [$owner, $business] = $this->fundedAgency();
        $token = $owner->createToken('withdrawal-test')->plainTextToken;

        $first = $this->withToken($token)
            ->withHeader('Idempotency-Key', 'withdrawal-request-1')
            ->postJson('/api/v1/withdrawals', $this->withdrawalPayload($business))
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.amount', '150000.00')
            ->assertJsonPath('data.fee_amount', '5000.00')
            ->assertJsonPath('data.net_amount', '145000.00')
            ->assertJsonPath('data.bank_account_number_masked', '••••5678')
            ->assertJsonMissingPath('data.bank_account_number');

        $this->withToken($token)
            ->withHeader('Idempotency-Key', 'withdrawal-request-1')
            ->postJson('/api/v1/withdrawals', $this->withdrawalPayload($business))
            ->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'));

        $withdrawal = Withdrawal::query()->findOrFail($first->json('data.id'));
        $rawAccountNumber = $withdrawal->getRawOriginal('bank_account_number');
        $this->assertNotSame('12345678', $rawAccountNumber);
        $this->assertSame('12345678', $withdrawal->bank_account_number);
        $this->assertSame(1, LedgerTransaction::query()->where('category', 'WITHDRAWAL_REQUEST')->count());
        $this->assertSame('50000.00', $this->account('BUSINESS.AVAILABLE:'.$business->id)->balance_cached);
        $this->assertSame('145000.00', $this->account('PLATFORM.WITHDRAWAL_PAYABLE')->balance_cached);
        $this->assertSame('5000.00', $this->account('PLATFORM.REVENUE.WITHDRAWAL_FEE')->balance_cached);
    }

    public function test_admin_can_review_and_record_manual_transfer_with_separate_ledger_categories(): void
    {
        Notification::fake();
        [$owner, $business] = $this->fundedAgency();
        $withdrawal = $this->requestWithdrawal($owner, $business);
        $admin = $this->admin();

        $this->apiAs($admin)
            ->getJson('/api/v1/admin/withdrawals/'.$withdrawal->getKey())
            ->assertOk()
            ->assertJsonPath('data.bank_account_number', '12345678')
            ->assertJsonPath('data.bank_account_name', 'GoMad Agency');

        $this->apiAs($admin)
            ->postJson('/api/v1/admin/withdrawals/'.$withdrawal->getKey().'/approve')
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->apiAs($admin)
            ->postJson('/api/v1/admin/withdrawals/'.$withdrawal->getKey().'/complete', [
                'transfer_reference' => 'BANK-TRANSFER-2026-001',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'paid');

        $withdrawal->refresh();
        $this->assertSame('BANK-TRANSFER-2026-001', $withdrawal->provider_reference);
        $this->assertNotNull($withdrawal->paid_at);
        $this->assertSame('0.00', $this->account('PLATFORM.WITHDRAWAL_PAYABLE')->balance_cached);
        $this->assertSame('55000.00', $this->account('PLATFORM.CASH')->balance_cached);
        $this->assertSame(1, LedgerTransaction::query()->where('category', 'WITHDRAWAL_PAID')->count());
        Notification::assertSentToTimes($owner, WithdrawalStatusNotification::class, 2);
    }

    public function test_failed_withdrawal_reverses_the_reservation_and_restores_available_balance(): void
    {
        Notification::fake();
        [$owner, $business] = $this->fundedAgency();
        $withdrawal = $this->requestWithdrawal($owner, $business);
        $admin = $this->admin();

        $this->apiAs($admin)
            ->postJson('/api/v1/admin/withdrawals/'.$withdrawal->getKey().'/fail', [
                'failure_code' => 'BANK_REJECTED',
                'failure_message' => 'Rekening tujuan tidak dapat menerima transfer.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.failure_code', 'BANK_REJECTED');

        $withdrawal->refresh();
        $this->assertNotNull($withdrawal->failed_at);
        $this->assertSame('200000.00', $this->account('BUSINESS.AVAILABLE:'.$business->id)->balance_cached);
        $this->assertSame('0.00', $this->account('PLATFORM.WITHDRAWAL_PAYABLE')->balance_cached);
        $this->assertSame('0.00', $this->account('PLATFORM.REVENUE.WITHDRAWAL_FEE')->balance_cached);
        $this->assertSame(1, LedgerTransaction::query()
            ->where('category', 'WITHDRAWAL_REVERSAL')
            ->where('reversal_of', $withdrawal->ledger_transaction_id)
            ->count());
        Notification::assertSentToTimes($owner, WithdrawalStatusNotification::class, 1);
    }

    public function test_non_owner_cannot_request_or_read_another_users_withdrawal_and_non_admin_cannot_see_full_account(): void
    {
        [$owner, $business] = $this->fundedAgency();
        $withdrawal = $this->requestWithdrawal($owner, $business);
        $other = User::factory()->create();

        $this->apiAs($other)
            ->getJson('/api/v1/withdrawals/'.$withdrawal->getKey())
            ->assertNotFound();
        $this->apiAs($other)
            ->getJson('/api/v1/admin/withdrawals/'.$withdrawal->getKey())
            ->assertForbidden();
        $this->apiAs($other)
            ->withHeader('Idempotency-Key', 'not-authorized')
            ->postJson('/api/v1/withdrawals', $this->withdrawalPayload($business))
            ->assertForbidden();
    }

    public function test_business_owner_cannot_reach_the_approval_endpoint(): void
    {
        [$owner, $business] = $this->fundedAgency();
        PlatformUserRole::query()->create([
            'user_id' => $owner->getKey(),
            'role' => 'admin',
            'assigned_by' => $owner->getKey(),
        ]);
        $withdrawal = $this->requestWithdrawal($owner, $business);

        // Satu akun satu fungsi: akun mitra tidak masuk area ops, sehingga pengaju
        // tidak mungkin menjadi penyetuju lewat HTTP — bahkan bila peran platform
        // ditempelkan ke akunnya. Penjagaan "pengaju tidak boleh menyetujui
        // pencairannya sendiri" tetap ada di domain sebagai lapis kedua.
        $this->apiAs($owner)
            ->postJson('/api/v1/admin/withdrawals/'.$withdrawal->getKey().'/approve')
            ->assertForbidden();
    }

    public function test_withdrawal_never_touches_held_collateral(): void
    {
        [$owner, $business] = $this->fundedAgency();

        // Jaminan (collateral) bukan saldo yang bisa dicairkan.
        app(PostLedgerTransaction::class)->execute(new PostLedgerTransactionData(
            category: LedgerCategory::CollateralTopup,
            idempotencyKey: 'withdrawal-test-collateral:'.$business->getKey(),
            entries: [
                new LedgerEntryInput('PLATFORM.CASH', LedgerDirection::Debit, '500000.00'),
                new LedgerEntryInput('BUSINESS.COLLATERAL:'.$business->getKey(), LedgerDirection::Credit, '500000.00'),
            ],
            referenceType: 'test_funding',
            createdBy: (int) $owner->getKey(),
        ));

        // Tersedia 200.000, jaminan 500.000: pencairan 500.000 harus ditolak.
        $this->apiAs($owner)
            ->withHeader('Idempotency-Key', 'withdrawal-collateral-'.Str::uuid())
            ->postJson('/api/v1/withdrawals', array_merge($this->withdrawalPayload($business), ['amount' => '500000']))
            ->assertStatus(409);

        $this->assertSame(0, Withdrawal::query()->count());
        $this->assertSame('200000.00', $this->account('BUSINESS.AVAILABLE:'.$business->getKey())->balance_cached);
        $this->assertSame('500000.00', $this->account('BUSINESS.COLLATERAL:'.$business->getKey())->balance_cached);

        // Pencairan dari saldo tersedia tidak menyentuh jaminan.
        $this->requestWithdrawal($owner, $business);
        $this->assertSame('500000.00', $this->account('BUSINESS.COLLATERAL:'.$business->getKey())->balance_cached);
    }

    private function fundedAgency(): array
    {
        $owner = User::factory()->accountType(AccountType::AgencyOwner)->create();
        $business = Business::query()->create([
            'type' => 'agency',
            'name' => 'Withdrawal Test Agency',
            'code' => 'GM-AGENCY-WD'.str_pad((string) (Business::query()->count() + 1), 5, '0', STR_PAD_LEFT),
            'owner_user_id' => $owner->getKey(),
            'status' => 'active',
        ]);
        $business->members()->attach($owner, [
            'role' => 'pemilik',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        app(LedgerAccountSeeder::class)->ensureBusinessAccounts($business);
        app(PostLedgerTransaction::class)->execute(new PostLedgerTransactionData(
            category: LedgerCategory::Adjustment,
            idempotencyKey: 'withdrawal-test-funding:'.$business->getKey(),
            entries: [
                new LedgerEntryInput('PLATFORM.CASH', LedgerDirection::Debit, '200000.00'),
                new LedgerEntryInput('BUSINESS.AVAILABLE:'.$business->getKey(), LedgerDirection::Credit, '200000.00'),
            ],
            referenceType: 'test_funding',
            createdBy: (int) $owner->getKey(),
        ));

        return [$owner, $business];
    }

    private function requestWithdrawal(User $owner, Business $business): Withdrawal
    {
        $response = $this->apiAs($owner)
            ->withHeader('Idempotency-Key', 'withdrawal-'.Str::uuid())
            ->postJson('/api/v1/withdrawals', $this->withdrawalPayload($business))
            ->assertCreated();

        return Withdrawal::query()->findOrFail($response->json('data.id'));
    }

    private function apiAs(User $user): static
    {
        Auth::forgetGuards();

        return $this->withToken($user->createToken('withdrawal-test')->plainTextToken);
    }

    private function admin(): User
    {
        $admin = User::factory()->accountType(AccountType::Admin)->create();
        PlatformUserRole::query()->create([
            'user_id' => $admin->getKey(),
            'role' => 'admin',
            'assigned_by' => $admin->getKey(),
        ]);

        return $admin;
    }

    /**
     * @return array<string, mixed>
     */
    private function withdrawalPayload(Business $business): array
    {
        return [
            'business_id' => $business->getKey(),
            'bank_code' => 'BCA',
            'bank_account_number' => '12345678',
            'bank_account_name' => 'GoMad Agency',
            'amount' => '150000',
        ];
    }

    private function account(string $code): LedgerAccount
    {
        return LedgerAccount::query()->where('code', $code)->firstOrFail();
    }
}
