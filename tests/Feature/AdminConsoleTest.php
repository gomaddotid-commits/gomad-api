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
use App\Core\Payment\Application\RequestWithdrawal;
use App\Models\Business;
use App\Models\LedgerAccount;
use App\Models\PlatformUserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Konsol operasional platform: antrean, verifikasi mitra, pencairan, settlement,
 * rekonsiliasi, pengguna, dan parameter — beserta paritas API-nya.
 */
class AdminConsoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.withdrawal.minimum_amount' => 100000,
            'services.withdrawal.admin_fee' => 5000,
            'services.mad_trans.ppn_basis_points' => 1100,
            'services.mad_warung.ppn_basis_points' => 1100,
            'services.rental.ppn_basis_points' => 1100,
        ]);
        $this->seed(AccessControlSeeder::class);
        app(LedgerAccountSeeder::class)->run();
    }

    public function test_admin_can_open_every_console_page(): void
    {
        [$admin] = $this->admin();
        $this->agencyWithPayable('Agency Ops', '300000');

        $pages = [
            '/admin' => 'Dasbor operasional',
            '/admin/pencairan' => 'Pencairan mitra',
            '/admin/settlement' => 'Settlement mitra',
            '/admin/rekonsiliasi' => 'Rekonsiliasi ledger',
            '/admin/mitra' => 'Verifikasi mitra',
            '/admin/pengguna' => 'Pengguna & peran',
            '/admin/parameter' => 'Parameter platform',
        ];

        foreach ($pages as $path => $marker) {
            $this->actingAs($admin)
                ->get($path)
                ->assertOk()
                ->assertSee($marker);
        }
    }

    public function test_non_admin_cannot_open_the_console(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        foreach (['/admin', '/admin/pencairan', '/admin/settlement', '/admin/rekonsiliasi', '/admin/mitra', '/admin/pengguna', '/admin/parameter'] as $path) {
            $this->actingAs($user)->get($path)->assertForbidden();
        }
    }

    public function test_verifying_a_partner_activates_a_draft_business(): void
    {
        [$admin] = $this->admin();
        $owner = User::factory()->create(['status' => 'active']);
        $business = Business::query()->create([
            'type' => 'warung',
            'name' => 'Warung Menunggu Verifikasi',
            'code' => 'GM-WARUNG-VERIFY',
            'owner_user_id' => $owner->getKey(),
            'status' => 'draft',
        ]);

        $this->assertNull($business->verified_at);

        $this->actingAs($admin)
            ->post('/admin/mitra/'.$business->getKey().'/verifikasi', ['verified' => 1])
            ->assertRedirect();

        $business->refresh();
        $this->assertNotNull($business->verified_at);
        $this->assertSame('active', $business->status);

        $this->actingAs($admin)
            ->post('/admin/mitra/'.$business->getKey().'/verifikasi', ['verified' => 0])
            ->assertRedirect();

        $this->assertNull($business->fresh()->verified_at);
        $this->assertSame('active', $business->fresh()->status);
    }

    public function test_admin_can_approve_then_complete_a_withdrawal(): void
    {
        [$admin] = $this->admin();
        [$agency] = $this->agencyWithPayable('Agency Pencairan', '500000');
        $owner = User::query()->findOrFail($agency->owner_user_id);

        $this->fundAvailable($agency, 500000);

        $withdrawal = app(RequestWithdrawal::class)->execute(
            $owner,
            $agency,
            'BCA',
            '1234567890',
            'Pemilik Agency',
            '200000',
            'test-withdrawal-1',
        );

        $this->assertSame('pending', $withdrawal->status);

        $this->actingAs($admin)
            ->post('/admin/pencairan/'.$withdrawal->getKey().'/setujui')
            ->assertRedirect();
        $this->assertSame('approved', $withdrawal->fresh()->status);

        $this->actingAs($admin)
            ->post('/admin/pencairan/'.$withdrawal->getKey().'/selesai', ['transfer_reference' => 'TRX-OK-1'])
            ->assertRedirect();
        $this->assertSame('paid', $withdrawal->fresh()->status);

        // Pemilik tidak boleh menyetujui pencairannya sendiri.
        $second = app(RequestWithdrawal::class)->execute(
            $owner,
            $agency,
            'BCA',
            '1234567890',
            'Pemilik Agency',
            '100000',
            'test-withdrawal-2',
        );

        $this->actingAs($owner)
            ->post('/admin/pencairan/'.$second->getKey().'/setujui')
            ->assertForbidden();
    }

    public function test_settlement_moves_payable_to_available(): void
    {
        [$admin] = $this->admin();
        [$agency, $payable] = $this->agencyWithPayable('Agency Settlement', '250000');

        $this->actingAs($admin)
            ->post('/admin/settlement/'.$agency->getKey(), ['amount' => (string) (int) $payable])
            ->assertRedirect();

        $this->assertSame('0.00', $this->balance('BUSINESS.PAYABLE:'.$agency->getKey()));
        $this->assertSame('250000.00', $this->balance('BUSINESS.AVAILABLE:'.$agency->getKey()));
    }

    public function test_console_api_is_available_for_admin_only(): void
    {
        [$admin] = $this->admin();
        $this->agencyWithPayable('Agency API', '400000');

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/overview')
            ->assertOk()
            ->assertJsonPath('data.ledger.accounts_mismatched', 0)
            ->assertJsonPath('data.ledger.unbalanced_transactions', 0)
            ->assertJsonStructure(['data' => ['queues' => [
                'withdrawals_pending',
                'documents_pending',
                'partners_unverified',
            ]]]);

        $this->getJson('/api/v1/admin/partners')->assertOk()->assertJsonStructure(['data', 'meta']);
        $this->getJson('/api/v1/admin/reconciliation')->assertOk()->assertJsonStructure(['data' => ['accounts', 'unbalanced_transactions']]);
        $this->getJson('/api/v1/admin/users')->assertOk()->assertJsonStructure(['data', 'meta']);
        $this->getJson('/api/v1/admin/parameters')->assertOk()->assertJsonPath('data.withdrawal.minimum_amount', '100000');

        $outsider = User::factory()->create(['status' => 'active']);
        Sanctum::actingAs($outsider);

        $this->getJson('/api/v1/admin/overview')->assertForbidden();
        $this->getJson('/api/v1/admin/partners')->assertForbidden();
    }

    private function admin(): array
    {
        $admin = User::factory()->accountType(AccountType::Admin)->create(['status' => 'active']);
        PlatformUserRole::query()->create(['user_id' => $admin->getKey(), 'role' => 'admin']);

        return [$admin];
    }

    private function agencyWithPayable(string $name, string $amount): array
    {
        $owner = User::factory()->create(['status' => 'active']);
        $agency = Business::query()->create([
            'type' => 'agency',
            'name' => $name,
            'code' => 'GM-OPS-'.str_pad((string) (Business::query()->count() + 1), 4, '0', STR_PAD_LEFT),
            'owner_user_id' => $owner->getKey(),
            'status' => 'active',
        ]);
        $agency->members()->attach($owner, [
            'role' => 'pemilik',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        app(LedgerAccountSeeder::class)->ensureBusinessAccounts($agency);

        app(PostLedgerTransaction::class)->execute(new PostLedgerTransactionData(
            category: LedgerCategory::BookingPaidOnline,
            idempotencyKey: 'ops-payable-'.$agency->getKey(),
            entries: [
                new LedgerEntryInput('PLATFORM.CASH', LedgerDirection::Debit, $amount),
                new LedgerEntryInput('BUSINESS.PAYABLE:'.$agency->getKey(), LedgerDirection::Credit, $amount),
            ],
            referenceType: 'test',
            referenceId: (int) $agency->getKey(),
            description: 'Fixture saldo kewajiban agency',
        ));

        return [$agency, $amount];
    }

    private function fundAvailable(Business $agency, int $amount): void
    {
        app(PostLedgerTransaction::class)->execute(new PostLedgerTransactionData(
            category: LedgerCategory::Adjustment,
            idempotencyKey: 'ops-available-'.$agency->getKey(),
            entries: [
                new LedgerEntryInput('PLATFORM.CASH', LedgerDirection::Debit, (string) $amount),
                new LedgerEntryInput('BUSINESS.AVAILABLE:'.$agency->getKey(), LedgerDirection::Credit, (string) $amount),
            ],
            referenceType: 'test',
            referenceId: (int) $agency->getKey(),
            description: 'Fixture saldo siap cair',
        ));
    }

    private function balance(string $code): string
    {
        return (string) LedgerAccount::query()->where('code', $code)->value('balance_cached');
    }
}
