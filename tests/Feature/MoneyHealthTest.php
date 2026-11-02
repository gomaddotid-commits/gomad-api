<?php

namespace Tests\Feature;

use App\Core\Access\Infrastructure\AccessControlSeeder;
use App\Core\Ledger\Application\PostLedgerTransaction;
use App\Core\Ledger\Domain\LedgerCategory;
use App\Core\Ledger\Domain\LedgerDirection;
use App\Core\Ledger\Domain\LedgerEntryInput;
use App\Core\Ledger\Domain\PostLedgerTransactionData;
use App\Core\Ledger\Infrastructure\LedgerAccountSeeder;
use App\Models\Business;
use App\Models\LedgerAccount;
use App\Models\Rental;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MoneyHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.midtrans.payment_timeout' => 30,
        ]);
        $this->seed(AccessControlSeeder::class);
        app(LedgerAccountSeeder::class)->run();
    }

    public function test_reconcile_succeeds_on_a_balanced_ledger(): void
    {
        $this->postCollateralTopup(150000);

        $this->artisan('gomad:reconcile')
            ->expectsOutputToContain('Rekonsiliasi bersih')
            ->assertExitCode(0);

        $this->artisan('gomad:money-health')->assertExitCode(0);
    }

    public function test_reconcile_fails_when_a_cached_balance_is_tampered_with(): void
    {
        $this->postCollateralTopup(150000);

        LedgerAccount::query()->where('code', 'PLATFORM.CASH')->update(['balance_cached' => '999999.00']);

        $this->artisan('gomad:reconcile')->assertExitCode(1);
        $this->artisan('gomad:money-health')->assertExitCode(1);
    }

    public function test_money_health_flags_a_deposit_still_held_after_the_rental_finished(): void
    {
        $owner = User::factory()->create(['status' => 'active']);
        $customer = User::factory()->create(['status' => 'active']);
        $agency = Business::query()->create([
            'type' => 'agency',
            'name' => 'Agency Deposit Macet',
            'code' => 'GM-AGENCY-MH1',
            'owner_user_id' => $owner->getKey(),
            'status' => 'active',
        ]);
        $agency->members()->attach($owner, ['role' => 'pemilik', 'status' => 'active', 'joined_at' => now()]);
        $vehicle = Vehicle::query()->create([
            'business_id' => $agency->getKey(),
            'name' => 'Avanza Macet',
            'plate_number' => 'B 1111 MH',
            'seat_layout' => ['A1'],
            'capacity' => 1,
            'rental_daily_price' => '300000.00',
            'status' => 'active',
        ]);

        Rental::query()->create([
            'code' => 'MT-RN-MH-0001',
            'business_id' => $agency->getKey(),
            'vehicle_id' => $vehicle->getKey(),
            'customer_id' => $customer->getKey(),
            'rental_type' => 'with_driver',
            'payment_mode' => 'ots',
            'start_at' => now()->subDays(3),
            'end_at' => now()->subDay(),
            'days' => 2,
            'price' => '600000.00',
            'driver_fee' => '0.00',
            'service_fee' => '10000.00',
            'platform_fee' => '18000.00',
            'ppn_amount' => '3080.00',
            'total_price' => '631080.00',
            'deposit_amount' => '200000.00',
            'deposit_status' => 'held',
            'status' => 'completed',
            'idempotency_key' => 'money-health-rental-1',
            'request_hash' => str_repeat('a', 64),
        ]);

        $this->artisan('gomad:money-health')
            ->expectsOutputToContain('deposit sewa masih ditahan')
            ->assertExitCode(1);
    }

    private function postCollateralTopup(int $amount): void
    {
        $agency = Business::query()->create([
            'type' => 'agency',
            'name' => 'Agency Uji Rekonsiliasi',
            'code' => 'GM-AGENCY-REC1',
            'owner_user_id' => User::factory()->create(['status' => 'active'])->getKey(),
            'status' => 'active',
        ]);
        app(LedgerAccountSeeder::class)->ensureBusinessAccounts($agency);

        app(PostLedgerTransaction::class)->execute(new PostLedgerTransactionData(
            category: LedgerCategory::CollateralTopup,
            idempotencyKey: 'money-health-topup-'.$agency->getKey(),
            entries: [
                new LedgerEntryInput('PLATFORM.CASH', LedgerDirection::Debit, (string) $amount),
                new LedgerEntryInput(
                    'BUSINESS.COLLATERAL:'.$agency->getKey(),
                    LedgerDirection::Credit,
                    (string) $amount,
                ),
            ],
            referenceType: 'test',
            referenceId: (int) $agency->getKey(),
            description: 'Fixture rekonsiliasi',
        ));
    }
}
