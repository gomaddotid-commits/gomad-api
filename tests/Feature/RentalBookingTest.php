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
use App\Core\Rental\Application\CreateRentalBooking;
use App\Core\Rental\Domain\RentalStatus;
use App\Core\Rental\Domain\RentalType;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use App\Models\PaymentIntent;
use App\Models\Rental;
use App\Models\TravelRoute;
use App\Models\TravelSchedule;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RentalBookingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.rental.service_fee' => 10000,
            'services.rental.platform_fee_basis_points' => 300,
            'services.rental.ppn_basis_points' => 1100,
            'services.midtrans.server_key' => 'sandbox-server-key-for-rental-tests',
            'services.midtrans.client_key' => 'sandbox-client-key-for-rental-tests',
            'services.midtrans.snap_url' => 'https://snap.sandbox.test/snap/v1/transactions',
            'services.midtrans.payment_timeout' => 30,
        ]);
        $this->seed(AccessControlSeeder::class);
        app(LedgerAccountSeeder::class)->run();
    }

    public function test_customer_books_a_rental_with_driver_and_fees_are_itemised(): void
    {
        [$agency, $vehicle, $driver] = $this->agencyWithRentableVehicle();
        $customer = User::factory()->create(['status' => 'active']);

        $rental = app(CreateRentalBooking::class)->execute(
            $customer,
            $agency,
            $vehicle,
            RentalType::WithDriver,
            now()->addDay()->setTime(8, 0),
            now()->addDays(3)->setTime(8, 0),
            'rental-test-1',
            $driver,
        );

        $this->assertSame(RentalStatus::Pending->value, $rental->status);
        $this->assertSame(2, (int) $rental->days);
        $this->assertSame('600000.00', $rental->price);
        $this->assertSame('200000.00', $rental->driver_fee);
        $this->assertSame('10000.00', $rental->service_fee);
        $this->assertSame('18000.00', $rental->platform_fee);
        $this->assertSame('3080.00', $rental->ppn_amount);
        $this->assertSame('831080.00', $rental->total_price);
    }

    public function test_self_drive_is_rejected_until_document_verification_exists(): void
    {
        [$agency, $vehicle] = $this->agencyWithRentableVehicle();
        $customer = User::factory()->create(['status' => 'active']);

        $this->expectException(\InvalidArgumentException::class);

        app(CreateRentalBooking::class)->execute(
            $customer,
            $agency,
            $vehicle,
            RentalType::SelfDrive,
            now()->addDay()->setTime(8, 0),
            now()->addDays(2)->setTime(8, 0),
            'rental-self-drive',
        );
    }

    public function test_vehicle_cannot_be_rented_twice_on_overlapping_dates(): void
    {
        [$agency, $vehicle, $driver] = $this->agencyWithRentableVehicle();
        $customer = User::factory()->create(['status' => 'active']);
        $create = app(CreateRentalBooking::class);

        $create->execute(
            $customer,
            $agency,
            $vehicle,
            RentalType::WithDriver,
            now()->addDay()->setTime(8, 0),
            now()->addDays(3)->setTime(8, 0),
            'rental-overlap-1',
            $driver,
        );

        $this->expectException(\InvalidArgumentException::class);
        $create->execute(
            $customer,
            $agency,
            $vehicle,
            RentalType::WithDriver,
            now()->addDays(2)->setTime(8, 0),
            now()->addDays(4)->setTime(8, 0),
            'rental-overlap-2',
            $driver,
        );
    }

    public function test_vehicle_booked_for_a_travel_schedule_cannot_be_rented(): void
    {
        [$agency, $vehicle, $driver] = $this->agencyWithRentableVehicle();
        $customer = User::factory()->create(['status' => 'active']);

        TravelSchedule::query()->create([
            'business_id' => $agency->getKey(),
            'vehicle_id' => $vehicle->getKey(),
            'route_id' => $this->routeIdFor($agency),
            'departure_date' => now()->addDays(2)->toDateString(),
            'departure_time' => '09:00:00',
            'travel_class' => 'regular',
            'price_per_seat' => '100000.00',
            'status' => 'published',
            'proposed_by' => $agency->owner_user_id,
            'allow_cod' => false,
            'payment_methods' => ['midtrans'],
        ]);

        $this->expectException(\InvalidArgumentException::class);

        app(CreateRentalBooking::class)->execute(
            $customer,
            $agency,
            $vehicle,
            RentalType::WithDriver,
            now()->addDay()->setTime(6, 0),
            now()->addDays(3)->setTime(20, 0),
            'rental-blocked-by-schedule',
            $driver,
        );
    }

    public function test_rental_must_start_at_least_two_hours_ahead(): void
    {
        [$agency, $vehicle, $driver] = $this->agencyWithRentableVehicle();
        $customer = User::factory()->create(['status' => 'active']);

        $this->expectException(\InvalidArgumentException::class);

        app(CreateRentalBooking::class)->execute(
            $customer,
            $agency,
            $vehicle,
            RentalType::WithDriver,
            now()->addMinutes(30),
            now()->addDay(),
            'rental-too-soon',
            $driver,
        );
    }

    public function test_customer_requests_rental_from_the_web_page_and_agency_walks_the_lifecycle(): void
    {
        [$agency, $vehicle, $driver] = $this->agencyWithRentableVehicle();
        $customer = User::factory()->create(['status' => 'active']);
        $owner = User::query()->findOrFail($agency->owner_user_id);

        $this->actingAs($customer)
            ->get('/mad-trans/rental/'.$agency->getKey())
            ->assertOk()
            ->assertSee('Sewa kendaraan');

        $this->actingAs($customer)
            ->post('/mad-trans/rental/'.$agency->getKey(), [
                'vehicle_id' => $vehicle->getKey(),
                'driver_membership_id' => $driver->getKey(),
                'start_at' => now()->addDay()->setTime(8, 0)->format('Y-m-d\TH:i'),
                'end_at' => now()->addDays(3)->setTime(8, 0)->format('Y-m-d\TH:i'),
            ])
            ->assertRedirect();

        $rental = Rental::query()->firstOrFail();
        $this->assertSame('pending', $rental->status);

        foreach ([
            'paid' => 'Tandai dibayar',
            'start' => 'Mulai sewa',
        ] as $action => $button) {
            $this->actingAs($owner)
                ->get('/mad-trans/agency/'.$agency->getKey().'/rental')
                ->assertOk()
                ->assertSee($button);

            $this->actingAs($owner)
                ->post('/mad-trans/agency/'.$agency->getKey().'/rentals/'.$rental->getKey().'/'.$action)
                ->assertSessionHasNoErrors();
        }

        $this->assertSame('active', $rental->fresh()->status);

        $this->actingAs($owner)
            ->post('/mad-trans/agency/'.$agency->getKey().'/rentals/'.$rental->getKey().'/return')
            ->assertSessionHasNoErrors();
        $this->actingAs($owner)
            ->post('/mad-trans/agency/'.$agency->getKey().'/rentals/'.$rental->getKey().'/complete')
            ->assertSessionHasNoErrors();

        $this->assertSame('completed', $rental->fresh()->status);
        $this->assertNotNull($rental->fresh()->completed_at);
    }

    public function test_rental_can_be_paid_online_and_settlement_posts_the_ledger(): void
    {
        [$agency, $vehicle, $driver] = $this->agencyWithRentableVehicle();
        $customer = User::factory()->create(['status' => 'active']);
        $rental = app(CreateRentalBooking::class)->execute(
            $customer,
            $agency,
            $vehicle,
            RentalType::WithDriver,
            now()->addDay()->setTime(8, 0),
            now()->addDays(3)->setTime(8, 0),
            'rental-pay-1',
            $driver,
        );

        Http::fake(['*' => Http::response([
            'token' => 'snap-token-rental',
            'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/snap-token-rental',
        ], 201)]);

        $this->actingAs($customer)
            ->post('/mad-trans/rental/'.$agency->getKey().'/rentals/'.$rental->getKey().'/pay')
            ->assertRedirect('https://app.sandbox.midtrans.com/snap/v4/redirection/snap-token-rental');

        $payment = PaymentIntent::query()->where('rental_id', $rental->getKey())->firstOrFail();
        $this->assertSame('rental', $payment->purpose);

        $payload = [
            'order_id' => $payment->provider_order_id,
            'status_code' => '200',
            'gross_amount' => (string) $payment->amount,
            'transaction_status' => 'settlement',
            'transaction_id' => 'rental-midtrans-'.$payment->getKey(),
            'fraud_status' => 'accept',
        ];
        $payload['signature_key'] = hash(
            'sha512',
            $payload['order_id'].$payload['status_code'].$payload['gross_amount'].'sandbox-server-key-for-rental-tests',
        );

        $this->postJson('/api/v1/webhooks/midtrans', $payload)->assertOk();

        $this->assertSame('paid', $rental->fresh()->status);

        $transaction = LedgerTransaction::query()
            ->where('idempotency_key', 'midtrans:payment:'.$payment->getKey())
            ->firstOrFail();
        $this->assertSame('RENTAL_PAID', $transaction->category);
        $this->assertSame('rental', $transaction->reference_type);

        $payable = LedgerAccount::query()->where('code', 'BUSINESS.PAYABLE:'.$agency->getKey())->firstOrFail();
        $this->assertSame('800000.00', $payable->balance_cached);

        // Callback duplikat tidak boleh menambah saldo lagi.
        $this->postJson('/api/v1/webhooks/midtrans', $payload)->assertOk();
        $this->assertSame('800000.00', $payable->fresh()->balance_cached);
    }

    public function test_deposit_is_held_as_liability_and_returned_when_rental_completes(): void
    {
        [$agency, $vehicle, $driver] = $this->agencyWithRentableVehicle();
        $vehicle->forceFill(['rental_deposit' => '500000.00'])->save();
        $customer = User::factory()->create(['status' => 'active']);
        $owner = User::query()->findOrFail($agency->owner_user_id);

        $rental = app(CreateRentalBooking::class)->execute(
            $customer,
            $agency,
            $vehicle->fresh(),
            RentalType::WithDriver,
            now()->addDay()->setTime(8, 0),
            now()->addDays(3)->setTime(8, 0),
            'rental-deposit-1',
            $driver,
        );

        $this->assertSame('500000.00', $rental->deposit_amount);
        $this->assertSame('pending', $rental->deposit_status);
        $this->assertSame('1331080.00', $rental->total_price);

        Http::fake(['*' => Http::response([
            'token' => 'snap-token-deposit',
            'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/snap-token-deposit',
        ], 201)]);

        $this->actingAs($customer)
            ->post('/mad-trans/rental/'.$agency->getKey().'/rentals/'.$rental->getKey().'/pay')
            ->assertRedirect();

        $payment = PaymentIntent::query()->where('rental_id', $rental->getKey())->firstOrFail();
        $payload = [
            'order_id' => $payment->provider_order_id,
            'status_code' => '200',
            'gross_amount' => (string) $payment->amount,
            'transaction_status' => 'settlement',
            'transaction_id' => 'rental-deposit-'.$payment->getKey(),
            'fraud_status' => 'accept',
        ];
        $payload['signature_key'] = hash(
            'sha512',
            $payload['order_id'].$payload['status_code'].$payload['gross_amount'].'sandbox-server-key-for-rental-tests',
        );
        $this->postJson('/api/v1/webhooks/midtrans', $payload)->assertOk();

        $this->assertSame('held', $rental->fresh()->deposit_status);
        $this->assertSame(
            '500000.00',
            LedgerAccount::query()->where('code', 'PLATFORM.DEPOSIT_PAYABLE')->firstOrFail()->balance_cached,
        );

        foreach (['start', 'return', 'complete'] as $action) {
            $this->actingAs($owner)
                ->post('/mad-trans/agency/'.$agency->getKey().'/rentals/'.$rental->getKey().'/'.$action)
                ->assertSessionHasNoErrors();
        }

        $completed = $rental->fresh();
        $this->assertSame('completed', $completed->status);
        $this->assertSame('returned', $completed->deposit_status);
        $this->assertNotNull($completed->deposit_returned_at);
        $this->assertSame(
            '0.00',
            LedgerAccount::query()->where('code', 'PLATFORM.DEPOSIT_PAYABLE')->firstOrFail()->balance_cached,
        );
        $this->assertSame(
            '831080.00',
            LedgerAccount::query()->where('code', 'PLATFORM.CASH')->firstOrFail()->balance_cached,
        );
    }

    public function test_deposit_deduction_is_paid_to_the_agency_and_reduces_the_refund(): void
    {
        [$agency, $vehicle, $driver, $owner, $customer, $rental] = $this->paidRentalWithDeposit();

        $payableCode = 'BUSINESS.PAYABLE:'.$agency->getKey();
        $payableBefore = (string) LedgerAccount::query()->where('code', $payableCode)->firstOrFail()->balance_cached;

        foreach (['start', 'return'] as $action) {
            $this->actingAs($owner)
                ->post('/mad-trans/agency/'.$agency->getKey().'/rentals/'.$rental->getKey().'/'.$action)
                ->assertSessionHasNoErrors();
        }

        $this->actingAs($owner)
            ->post('/mad-trans/agency/'.$agency->getKey().'/rentals/'.$rental->getKey().'/complete', [
                'deposit_deduction' => '200000',
                'deposit_deduction_reason' => 'Lecet bumper depan',
            ])
            ->assertSessionHasNoErrors();

        $completed = $rental->fresh();
        $this->assertSame('completed', $completed->status);
        $this->assertSame('returned', $completed->deposit_status);
        $this->assertSame('200000.00', $completed->deposit_deducted_amount);
        $this->assertSame('300000.00', $completed->deposit_refunded_amount);
        $this->assertSame('Lecet bumper depan', $completed->deposit_deduction_reason);

        // Kewajiban deposit lunas: 200.000 jadi hak agency, 300.000 kembali ke penyewa.
        $this->assertSame(
            '0.00',
            LedgerAccount::query()->where('code', 'PLATFORM.DEPOSIT_PAYABLE')->firstOrFail()->balance_cached,
        );
        $this->assertSame(
            (float) $payableBefore + 200000.0,
            (float) LedgerAccount::query()->where('code', $payableCode)->firstOrFail()->balance_cached,
        );

        $deduction = LedgerTransaction::query()
            ->where('idempotency_key', 'rental:deposit-deduction:'.$rental->getKey())
            ->firstOrFail();
        $this->assertSame(LedgerCategory::DepositDeducted->value, $deduction->category);
    }

    public function test_deposit_deduction_cannot_exceed_the_held_deposit(): void
    {
        [$agency, $vehicle, $driver, $owner, $customer, $rental] = $this->paidRentalWithDeposit();

        foreach (['start', 'return'] as $action) {
            $this->actingAs($owner)
                ->post('/mad-trans/agency/'.$agency->getKey().'/rentals/'.$rental->getKey().'/'.$action)
                ->assertSessionHasNoErrors();
        }

        $this->actingAs($owner)
            ->post('/mad-trans/agency/'.$agency->getKey().'/rentals/'.$rental->getKey().'/complete', [
                'deposit_deduction' => '600000',
                'deposit_deduction_reason' => 'Kerusakan berat',
            ])
            ->assertSessionHasErrors('deposit_deduction');

        $this->assertSame('returned', $rental->fresh()->status);
        $this->assertSame('held', $rental->fresh()->deposit_status);

        // Potongan tanpa alasan juga ditolak.
        $this->actingAs($owner)
            ->post('/mad-trans/agency/'.$agency->getKey().'/rentals/'.$rental->getKey().'/complete', [
                'deposit_deduction' => '100000',
            ])
            ->assertSessionHasErrors('deposit_deduction_reason');

        $this->assertSame('returned', $rental->fresh()->status);
    }

    public function test_charter_requires_itinerary_and_is_priced_like_a_with_driver_rental(): void
    {
        [$agency, $vehicle, $driver] = $this->agencyWithRentableVehicle();
        $customer = User::factory()->create(['status' => 'active']);
        $create = app(CreateRentalBooking::class);

        try {
            $create->execute(
                $customer,
                $agency,
                $vehicle,
                RentalType::Charter,
                now()->addDay()->setTime(8, 0),
                now()->addDays(3)->setTime(8, 0),
                'charter-without-itinerary',
                $driver,
            );
            $this->fail('Charter tanpa itinerary seharusnya ditolak.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('itinerary', $exception->getMessage());
        }

        $charter = $create->execute(
            $customer,
            $agency,
            $vehicle,
            RentalType::Charter,
            now()->addDay()->setTime(8, 0),
            now()->addDays(3)->setTime(8, 0),
            'charter-with-itinerary',
            $driver,
            'Surabaya - Malang - Batu',
        );

        $this->assertSame('charter', $charter->rental_type);
        $this->assertSame('Surabaya - Malang - Batu', $charter->itinerary);
        $this->assertSame('831080.00', $charter->total_price);

        $this->actingAs($customer)
            ->get('/mad-trans/rental/'.$agency->getKey())
            ->assertOk()
            ->assertSee('Ajukan charter');
    }

    public function test_ots_needs_collateral_and_collects_platform_fee_from_it(): void
    {
        [$agency, $vehicle, $driver] = $this->agencyWithRentableVehicle();
        $customer = User::factory()->create(['status' => 'active']);
        $owner = User::query()->findOrFail($agency->owner_user_id);
        $create = app(CreateRentalBooking::class);

        try {
            $create->execute(
                $customer, $agency, $vehicle, RentalType::WithDriver,
                now()->addDay()->setTime(8, 0), now()->addDays(3)->setTime(8, 0),
                'ots-no-collateral', $driver, null, 'ots',
            );
            $this->fail('OTS tanpa jaminan seharusnya ditolak.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('OTS', $exception->getMessage());
        }

        $this->fundCollateral($agency, 50000);

        $rental = $create->execute(
            $customer, $agency, $vehicle, RentalType::WithDriver,
            now()->addDay()->setTime(8, 0), now()->addDays(3)->setTime(8, 0),
            'ots-with-collateral', $driver, null, 'ots',
        );

        $this->assertSame('ots', $rental->payment_mode);
        $this->assertSame(1, DB::table('wallet_holds')->where('source_type', 'rental')
            ->where('source_id', $rental->getKey())->where('status', 'active')->count());

        $this->actingAs($owner)
            ->post('/mad-trans/agency/'.$agency->getKey().'/rentals/'.$rental->getKey().'/paid')
            ->assertSessionHasNoErrors();

        $fresh = $rental->fresh();
        $this->assertSame('paid', $fresh->status);
        $this->assertNotNull($fresh->ots_fee_transaction_id);
        $this->assertSame(0, DB::table('wallet_holds')->where('status', 'active')->count());
        $this->assertSame(
            '18920.00',
            LedgerAccount::query()->where('code', 'BUSINESS.COLLATERAL:'.$agency->getKey())->firstOrFail()->balance_cached,
        );
    }

    public function test_cancelling_pending_ots_releases_its_hold(): void
    {
        [$agency, $vehicle, $driver] = $this->agencyWithRentableVehicle();
        $customer = User::factory()->create(['status' => 'active']);
        $owner = User::query()->findOrFail($agency->owner_user_id);
        $this->fundCollateral($agency, 50000);

        $rental = app(CreateRentalBooking::class)->execute(
            $customer, $agency, $vehicle, RentalType::WithDriver,
            now()->addDay()->setTime(8, 0), now()->addDays(3)->setTime(8, 0),
            'ots-cancel', $driver, null, 'ots',
        );

        $this->actingAs($owner)
            ->post('/mad-trans/agency/'.$agency->getKey().'/rentals/'.$rental->getKey().'/cancel')
            ->assertSessionHasNoErrors();

        $this->assertSame('cancelled', $rental->fresh()->status);
        $this->assertSame(0, DB::table('wallet_holds')->where('status', 'active')->count());
    }

    /**
     * @return array{0: Business, 1: Vehicle, 2: BusinessMember, 3: User, 4: User, 5: Rental}
     */
    private function paidRentalWithDeposit(string $deposit = '500000.00'): array
    {
        [$agency, $vehicle, $driver] = $this->agencyWithRentableVehicle();
        $vehicle->forceFill(['rental_deposit' => $deposit])->save();
        $customer = User::factory()->create(['status' => 'active']);
        $owner = User::query()->findOrFail($agency->owner_user_id);

        $rental = app(CreateRentalBooking::class)->execute(
            $customer,
            $agency,
            $vehicle->fresh(),
            RentalType::WithDriver,
            now()->addDay()->setTime(8, 0),
            now()->addDays(3)->setTime(8, 0),
            'rental-deduction-'.$agency->getKey(),
            $driver,
        );

        Http::fake(['*' => Http::response([
            'token' => 'snap-token-deduction',
            'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/snap-token-deduction',
        ], 201)]);

        $this->actingAs($customer)
            ->post('/mad-trans/rental/'.$agency->getKey().'/rentals/'.$rental->getKey().'/pay')
            ->assertRedirect();

        $payment = PaymentIntent::query()->where('rental_id', $rental->getKey())->firstOrFail();
        $payload = [
            'order_id' => $payment->provider_order_id,
            'status_code' => '200',
            'gross_amount' => (string) $payment->amount,
            'transaction_status' => 'settlement',
            'transaction_id' => 'rental-deduction-'.$payment->getKey(),
            'fraud_status' => 'accept',
        ];
        $payload['signature_key'] = hash(
            'sha512',
            $payload['order_id'].$payload['status_code'].$payload['gross_amount'].'sandbox-server-key-for-rental-tests',
        );
        $this->postJson('/api/v1/webhooks/midtrans', $payload)->assertOk();

        $this->assertSame('held', $rental->fresh()->deposit_status);

        return [$agency, $vehicle, $driver, $owner, $customer, $rental];
    }

    private function fundCollateral(Business $agency, int $amount): void
    {
        app(LedgerAccountSeeder::class)->ensureBusinessAccounts($agency);
        app(PostLedgerTransaction::class)->execute(new PostLedgerTransactionData(
            category: LedgerCategory::CollateralTopup,
            idempotencyKey: 'test:rental-collateral:'.$agency->getKey(),
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
            description: 'Fixture jaminan OTS',
        ));
    }

    private function agencyWithRentableVehicle(): array
    {
        $owner = User::factory()->accountType(AccountType::AgencyOwner)->create(['status' => 'active']);
        $driverUser = User::factory()->accountType(AccountType::Driver)->create(['status' => 'active']);
        $agency = Business::query()->create([
            'type' => 'agency',
            'name' => 'Rental Test',
            'code' => 'GM-AGENCY-RNT1',
            'owner_user_id' => $owner->getKey(),
            'status' => 'active',
        ]);
        $agency->members()->attach($owner, ['role' => 'pemilik', 'status' => 'active', 'joined_at' => now()]);
        $agency->members()->attach($driverUser, ['role' => 'driver', 'status' => 'active', 'joined_at' => now()]);

        $driver = BusinessMember::query()
            ->where('business_id', $agency->getKey())
            ->where('user_id', $driverUser->getKey())
            ->firstOrFail();

        $vehicle = Vehicle::query()->create([
            'business_id' => $agency->getKey(),
            'name' => 'Avanza Rental',
            'plate_number' => 'B 4321 RNT',
            'seat_layout' => ['A1', 'A2', 'B1', 'B2'],
            'capacity' => 4,
            'rental_daily_price' => '300000.00',
            'rental_driver_daily_fee' => '100000.00',
            'status' => 'active',
        ]);

        return [$agency, $vehicle, $driver];
    }

    private function routeIdFor(Business $agency): int
    {
        $route = TravelRoute::query()->create([
            'business_id' => $agency->getKey(),
            'origin_city' => 'Jakarta',
            'destination_city' => 'Bandung',
            'status' => 'active',
        ]);

        return $route->getKey();
    }
}
