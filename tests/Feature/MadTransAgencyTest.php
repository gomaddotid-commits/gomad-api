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
use App\Models\Business;
use App\Models\LedgerAccount;
use App\Models\TravelSchedule;
use App\Models\User;
use App\Models\WalletHold;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MadTransAgencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.mad_trans.service_fee' => 5000,
            'services.mad_trans.platform_fee_basis_points' => 300,
            'services.mad_trans.ppn_basis_points' => 1100,
        ]);
        $this->seed(AccessControlSeeder::class);
        app(LedgerAccountSeeder::class)->run();
    }

    public function test_cod_schedule_can_be_enabled_but_booking_is_rejected_without_available_collateral(): void
    {
        [$owner, $agency] = $this->agency();
        $schedule = $this->createAndApproveSchedule($owner, $agency, 2);

        $this->apiAs($owner)
            ->postJson('/api/v1/mad-trans/schedules/'.$schedule.'/enable-cod')
            ->assertOk()
            ->assertJsonPath('data.allow_cod', true);
        $scheduleModel = TravelSchedule::query()->findOrFail($schedule);
        $stopIds = $scheduleModel->route->stops()->orderBy('stop_order')->pluck('id')->all();
        $customer = User::factory()->create(['status' => 'active']);
        $this->apiAs($customer)
            ->withHeader('Idempotency-Key', 'cod-without-collateral')
            ->postJson('/api/v1/mad-trans/bookings', [
                'schedule_id' => $schedule,
                'origin_stop_id' => $stopIds[0],
                'destination_stop_id' => $stopIds[1],
                'payment_method' => 'cod',
                'passengers' => [['name' => 'Penumpang Satu', 'seat_number' => 'A1']],
            ])
            ->assertConflict()
            ->assertJsonPath('code', 'COD_COLLATERAL_INSUFFICIENT');
        $this->apiAs($customer)
            ->withHeader('Idempotency-Key', 'online-without-collateral')
            ->postJson('/api/v1/mad-trans/bookings', [
                'schedule_id' => $schedule,
                'origin_stop_id' => $stopIds[0],
                'destination_stop_id' => $stopIds[1],
                'payment_method' => 'midtrans',
                'passengers' => [['name' => 'Penumpang Dua', 'seat_number' => 'A2']],
            ])
            ->assertCreated()
            ->assertJsonPath('data.payment_method', 'midtrans');

        $this->assertDatabaseHas('schedules', [
            'id' => $schedule,
            'status' => 'published',
            'allow_cod' => true,
            'risk_status' => 'ok',
        ]);
        $this->assertDatabaseCount('wallet_holds', 0);
        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_existing_cod_booking_is_not_cancelled_when_free_collateral_is_insufficient_for_another(): void
    {
        [$owner, $agency] = $this->agency();
        $customer = User::factory()->create(['status' => 'active']);
        $this->fundCollateral($agency, 10545);
        $scheduleId = $this->createAndApproveSchedule($owner, $agency, 2);
        $this->apiAs($owner)->postJson('/api/v1/mad-trans/schedules/'.$scheduleId.'/enable-cod')->assertOk();
        $schedule = TravelSchedule::query()->findOrFail($scheduleId);
        $stopIds = $schedule->route->stops()->orderBy('stop_order')->pluck('id')->all();
        $createBooking = fn (string $key, string $seat) => $this->apiAs($customer)
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/mad-trans/bookings', [
                'schedule_id' => $scheduleId,
                'origin_stop_id' => $stopIds[0],
                'destination_stop_id' => $stopIds[1],
                'payment_method' => 'cod',
                'passengers' => [['name' => 'Penumpang', 'seat_number' => $seat]],
            ]);
        $firstBooking = $createBooking('cod-first-seat', 'A1')->assertCreated();
        $createBooking('cod-second-seat', 'A2')
            ->assertConflict()
            ->assertJsonPath('code', 'COD_COLLATERAL_INSUFFICIENT');

        $this->assertDatabaseHas('bookings', [
            'id' => $firstBooking->json('data.id'),
            'status' => 'confirmed',
            'payment_method' => 'cod',
        ]);
        $this->assertDatabaseCount('bookings', 1);
        $this->assertSame(1, WalletHold::query()->where('status', 'active')->count());
    }

    public function test_cod_activation_does_not_reserve_collateral_for_unused_vehicle_seats(): void
    {
        [$owner, $agency] = $this->agency();
        $this->fundCollateral($agency, 21090);
        $schedule = $this->createAndApproveSchedule($owner, $agency, 2);

        $before = $this->account('BUSINESS.COLLATERAL:'.$agency->getKey())->balance_cached;
        $this->apiAs($owner)
            ->postJson('/api/v1/mad-trans/schedules/'.$schedule.'/enable-cod')
            ->assertOk()
            ->assertJsonPath('data.allow_cod', true)
            ->assertJsonPath('data.risk_status', 'ok')
            ->assertJsonPath('data.payment_methods.1', 'cod');

        $this->assertDatabaseCount('wallet_holds', 0);
        $this->assertSame('21090.00', $this->account('BUSINESS.COLLATERAL:'.$agency->getKey())->balance_cached);
        $this->assertSame($before, $this->account('BUSINESS.COLLATERAL:'.$agency->getKey())->balance_cached);
        $this->assertSame(0, WalletHold::query()->where('status', 'active')->count());
    }

    public function test_driver_boarding_cod_passenger_collects_fee_once_from_collateral(): void
    {
        [$owner, $agency] = $this->agency();
        $driver = User::factory()->accountType(AccountType::Driver)->create(['status' => 'active']);
        $agency->members()->attach($driver, [
            'role' => 'driver',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $driverMembershipId = (int) DB::table('business_members')
            ->where('business_id', $agency->getKey())
            ->where('user_id', $driver->getKey())
            ->value('id');
        $customer = User::factory()->create(['status' => 'active']);
        $this->fundCollateral($agency, 21090);
        $scheduleId = $this->createAndApproveSchedule($owner, $agency, 2, $driverMembershipId);
        $this->apiAs($owner)
            ->postJson('/api/v1/mad-trans/schedules/'.$scheduleId.'/enable-cod')
            ->assertOk();

        $schedule = TravelSchedule::query()->findOrFail($scheduleId);
        $stopIds = $schedule->route->stops()->orderBy('stop_order')->pluck('id')->all();
        $booking = $this->apiAs($customer)
            ->withHeader('Idempotency-Key', 'cod-booking-1')
            ->postJson('/api/v1/mad-trans/bookings', [
                'schedule_id' => $scheduleId,
                'origin_stop_id' => $stopIds[0],
                'destination_stop_id' => $stopIds[1],
                'payment_method' => 'cod',
                'passengers' => [[
                    'name' => 'Penumpang Satu',
                    'seat_number' => 'A1',
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.payment_method', 'cod')
            ->assertJsonPath('data.status', 'confirmed');
        $bookingId = (int) $booking->json('data.id');
        $passengerId = (int) DB::table('booking_passengers')
            ->where('booking_id', $bookingId)
            ->value('id');

        Carbon::setTestNow(now()->addDays(3));
        try {
            $this->apiAs($driver)
                ->postJson('/api/v1/mad-trans/schedules/'.$scheduleId.'/start')
                ->assertOk()
                ->assertJsonPath('data.status', 'in_progress');

            $boardUrl = '/api/v1/mad-trans/bookings/'.$bookingId.'/passengers/'.$passengerId.'/board';
            $boardResponse = $this->apiAs($driver)
                ->postJson($boardUrl)
                ->assertOk()
                ->assertJsonPath('data.status', 'on_going');
            $this->assertIsString($boardResponse->json('data.passengers.0.boarded_at'));
            $this->apiAs($driver)->postJson($boardUrl)->assertOk();
            $this->apiAs($driver)
                ->postJson('/api/v1/mad-trans/schedules/'.$scheduleId.'/finish')
                ->assertOk()
                ->assertJsonPath('data.status', 'completed');
        } finally {
            Carbon::setTestNow();
        }

        $this->assertDatabaseCount('ledger_transactions', 2);
        $this->assertDatabaseHas('ledger_transactions', [
            'category' => 'COD_FEE_COLLECTED',
            'reference_type' => 'travel_booking_passenger',
            'reference_id' => $passengerId,
            'amount' => '10545.00',
        ]);
        $this->assertDatabaseHas('bookings', [
            'id' => $bookingId,
            'status' => 'completed',
        ]);
        $this->assertDatabaseHas('wallet_holds', [
            'source_type' => 'travel_cod_passenger',
            'source_id' => $passengerId,
            'reason' => 'cod_collateral',
            'status' => 'released',
        ]);
        $this->assertSame('10545.00', $this->account('BUSINESS.COLLATERAL:'.$agency->getKey())->balance_cached);
    }

    public function test_customer_can_cancel_cod_booking_before_trip_and_release_its_seat_hold(): void
    {
        [$owner, $agency] = $this->agency();
        $customer = User::factory()->create(['status' => 'active']);
        $this->fundCollateral($agency, 21090);
        $scheduleId = $this->createAndApproveSchedule($owner, $agency, 2);
        $this->apiAs($owner)->postJson('/api/v1/mad-trans/schedules/'.$scheduleId.'/enable-cod')->assertOk();
        $schedule = TravelSchedule::query()->findOrFail($scheduleId);
        $stopIds = $schedule->route->stops()->orderBy('stop_order')->pluck('id')->all();
        $booking = $this->apiAs($customer)
            ->withHeader('Idempotency-Key', 'cod-booking-cancel')
            ->postJson('/api/v1/mad-trans/bookings', [
                'schedule_id' => $scheduleId,
                'origin_stop_id' => $stopIds[0],
                'destination_stop_id' => $stopIds[1],
                'payment_method' => 'cod',
                'passengers' => [['name' => 'Penumpang Satu', 'seat_number' => 'A1']],
            ])
            ->assertCreated();

        $this->apiAs($customer)
            ->postJson('/api/v1/mad-trans/bookings/'.$booking->json('data.id').'/cancel')
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertDatabaseHas('wallet_holds', [
            'source_type' => 'travel_cod_passenger',
            'source_id' => DB::table('booking_passengers')
                ->where('booking_id', $booking->json('data.id'))
                ->value('id'),
            'reason' => 'cod_collateral',
            'status' => 'released',
        ]);
        $this->assertDatabaseHas('booking_passengers', [
            'booking_id' => $booking->json('data.id'),
            'seat_number' => null,
        ]);
    }

    public function test_driver_can_void_cod_hold_for_no_show_without_posting_a_fee(): void
    {
        [$owner, $agency] = $this->agency();
        $driver = User::factory()->accountType(AccountType::Driver)->create(['status' => 'active']);
        $agency->members()->attach($driver, [
            'role' => 'driver',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $driverMembershipId = (int) DB::table('business_members')
            ->where('business_id', $agency->getKey())
            ->where('user_id', $driver->getKey())
            ->value('id');
        $customer = User::factory()->create(['status' => 'active']);
        $this->fundCollateral($agency, 10545);
        $scheduleId = $this->createAndApproveSchedule($owner, $agency, 2, $driverMembershipId);
        $this->apiAs($owner)->postJson('/api/v1/mad-trans/schedules/'.$scheduleId.'/enable-cod')->assertOk();
        $schedule = TravelSchedule::query()->findOrFail($scheduleId);
        $stopIds = $schedule->route->stops()->orderBy('stop_order')->pluck('id')->all();
        $booking = $this->apiAs($customer)
            ->withHeader('Idempotency-Key', 'cod-booking-no-show')
            ->postJson('/api/v1/mad-trans/bookings', [
                'schedule_id' => $scheduleId,
                'origin_stop_id' => $stopIds[0],
                'destination_stop_id' => $stopIds[1],
                'payment_method' => 'cod',
                'passengers' => [['name' => 'Penumpang Satu', 'seat_number' => 'A1']],
            ])
            ->assertCreated();
        $bookingId = (int) $booking->json('data.id');
        $passengerId = (int) DB::table('booking_passengers')
            ->where('booking_id', $bookingId)
            ->value('id');

        Carbon::setTestNow(now()->addDays(3));
        try {
            $this->apiAs($driver)->postJson('/api/v1/mad-trans/schedules/'.$scheduleId.'/start')->assertOk();
            $noShowUrl = '/api/v1/mad-trans/bookings/'.$bookingId.'/passengers/'.$passengerId.'/no-show';
            $this->apiAs($driver)->postJson($noShowUrl)->assertOk();
            $this->apiAs($driver)->postJson($noShowUrl)->assertOk();
            $this->apiAs($driver)
                ->postJson('/api/v1/mad-trans/schedules/'.$scheduleId.'/finish')
                ->assertOk()
                ->assertJsonPath('data.status', 'completed');
        } finally {
            Carbon::setTestNow();
        }

        $this->assertDatabaseHas('booking_passengers', [
            'id' => $passengerId,
            'boarded_at' => null,
        ]);
        $this->assertNotNull(DB::table('booking_passengers')->where('id', $passengerId)->value('no_show_at'));
        $this->assertDatabaseHas('wallet_holds', [
            'source_type' => 'travel_cod_passenger',
            'source_id' => $passengerId,
            'reason' => 'cod_collateral',
            'status' => 'released',
        ]);
        $this->assertDatabaseCount('ledger_transactions', 1);
        $this->assertSame('10545.00', $this->account('BUSINESS.COLLATERAL:'.$agency->getKey())->balance_cached);
    }

    private function agency(): array
    {
        $owner = User::factory()->accountType(AccountType::AgencyOwner)->create(['status' => 'active']);
        $agency = Business::query()->create([
            'type' => 'agency',
            'name' => 'Travel Agency',
            'code' => 'GM-AGENCY-0001',
            'owner_user_id' => $owner->getKey(),
            'status' => 'active',
        ]);
        $agency->members()->attach($owner, [
            'role' => 'pemilik',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return [$owner, $agency];
    }

    private function createAndApproveSchedule(
        User $owner,
        Business $agency,
        int $seats,
        ?int $driverMembershipId = null,
    ): int {
        $route = $this->apiAs($owner)
            ->postJson('/api/v1/mad-trans/businesses/'.$agency->getKey().'/routes', [
                'stops' => [
                    ['city_name' => 'Sumenep', 'name' => 'Terminal Sumenep'],
                    ['city_name' => 'Surabaya', 'name' => 'Terminal Bungurasih'],
                ],
                'pricing' => [['origin_order' => 1, 'destination_order' => 2, 'price' => '150000']],
            ])
            ->assertCreated();
        $vehicle = $this->apiAs($owner)
            ->postJson('/api/v1/mad-trans/businesses/'.$agency->getKey().'/vehicles', [
                'name' => 'Hiace',
                'plate_number' => 'M 1234 AB',
                'seat_layout' => array_map(fn (int $seat): string => 'A'.$seat, range(1, $seats)),
            ])
            ->assertCreated();
        $schedule = $this->apiAs($owner)
            ->postJson('/api/v1/mad-trans/businesses/'.$agency->getKey().'/schedules', [
                'route_id' => $route->json('data.id'),
                'vehicle_id' => $vehicle->json('data.id'),
                'driver_membership_id' => $driverMembershipId,
                'departure_at' => now()->addDays(2)->setTime(8, 0)->toIso8601String(),
                'travel_class' => 'regular',
                'price_per_seat' => '150000',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending_approval');
        $id = (int) $schedule->json('data.id');

        $this->apiAs($owner)
            ->postJson('/api/v1/mad-trans/schedules/'.$id.'/approve')
            ->assertOk()
            ->assertJsonPath('data.status', 'published');

        return $id;
    }

    private function fundCollateral(Business $agency, int $amount): void
    {
        app(LedgerAccountSeeder::class)->ensureBusinessAccounts($agency);
        app(PostLedgerTransaction::class)->execute(new PostLedgerTransactionData(
            category: LedgerCategory::CollateralTopup,
            idempotencyKey: 'test:cod-collateral:'.$agency->getKey(),
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
            description: 'Fixture jaminan COD',
            createdBy: (int) $agency->owner_user_id,
        ));
    }

    private function apiAs(User $user): static
    {
        Auth::forgetGuards();

        return $this->withToken($user->createToken('mad-trans-agency-test')->plainTextToken);
    }

    private function account(string $code): LedgerAccount
    {
        return LedgerAccount::query()->where('code', $code)->firstOrFail();
    }
}
