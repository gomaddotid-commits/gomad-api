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
use App\Core\MadTrans\Application\CreateTravelBooking;
use App\Models\BookingPassenger;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\RoutePricing;
use App\Models\RouteStop;
use App\Models\TravelRoute;
use App\Models\TravelSchedule;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Parity API untuk layar supir.
 *
 * Sebelum ini API hanya punya aksinya (board / no-show / start / finish) tanpa
 * cara membaca tugas. Aplikasi native butuh `index` dan `show` lebih dulu;
 * tes ini mengunci bentuk jawabannya sekaligus memastikan penjaga aksesnya
 * sama ketatnya dengan web.
 */
class DriverScheduleApiTest extends TestCase
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

    public function test_driver_reads_active_and_completed_tasks(): void
    {
        [$agency, $driver, $driverMembershipId] = $this->agencyWithDriver();
        [$schedule] = $this->codSchedule($agency, $driverMembershipId);

        Sanctum::actingAs($driver);

        $this->getJson('/api/v1/mad-trans/driver/schedules')
            ->assertOk()
            ->assertJsonPath('data.active.0.id', $schedule->getKey())
            ->assertJsonPath('data.active.0.route.origin_city', 'Jakarta')
            ->assertJsonPath('data.active.0.route.destination_city', 'Bandung')
            ->assertJsonPath('data.active.0.vehicle.plate_number', 'B 7777 DRV')
            ->assertJsonPath('data.active.0.can_start', true)
            ->assertJsonPath('data.active.0.can_finish', false)
            ->assertJsonPath('data.completed', []);

        $schedule->forceFill(['status' => 'completed', 'finished_at' => now()])->save();

        $this->getJson('/api/v1/mad-trans/driver/schedules')
            ->assertOk()
            ->assertJsonPath('data.active', [])
            ->assertJsonPath('data.completed.0.id', $schedule->getKey())
            ->assertJsonPath('data.completed.0.can_start', false);
    }

    public function test_detail_lists_passengers_and_allows_boarding_only_after_start(): void
    {
        [$agency, $driver, $driverMembershipId] = $this->agencyWithDriver();
        $this->fundCollateral($agency, 30000);
        [$schedule, $originId, $destinationId] = $this->codSchedule($agency, $driverMembershipId);

        $customer = User::factory()->create(['status' => 'active']);
        $booking = app(CreateTravelBooking::class)->execute(
            $customer,
            $schedule,
            $originId,
            $destinationId,
            [
                ['name' => 'Penumpang Naik', 'phone' => null, 'seat_number' => 'A1'],
                ['name' => 'Penumpang Batal', 'phone' => null, 'seat_number' => 'A2'],
            ],
            'cod',
            'driver-api-test',
        );

        Sanctum::actingAs($driver);

        $this->getJson('/api/v1/mad-trans/driver/schedules/'.$schedule->getKey())
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.passenger_count', 2)
            ->assertJsonPath('data.boarded_count', 0)
            ->assertJsonPath('data.bookings.0.code', $booking->code)
            ->assertJsonPath('data.bookings.0.passengers.0.name', 'Penumpang Naik')
            // Belum berjalan: tombol boarding harus mati walau kursinya ada.
            ->assertJsonPath('data.bookings.0.passengers.0.can_board', false);

        // Perjalanan hanya boleh dimulai pada hari keberangkatan; jadwal fixture
        // dibuat untuk besok, jadi waktu uji dimajukan seperti tes web supir.
        Carbon::setTestNow(now()->addDay()->setTime(8, 0));

        try {
            $this->postJson('/api/v1/mad-trans/schedules/'.$schedule->getKey().'/start')->assertOk();

            $this->getJson('/api/v1/mad-trans/driver/schedules/'.$schedule->getKey())
                ->assertOk()
                ->assertJsonPath('data.status', 'in_progress')
                ->assertJsonPath('data.can_finish', true)
                ->assertJsonPath('data.bookings.0.passengers.0.can_board', true)
                ->assertJsonPath('data.bookings.0.passengers.1.can_mark_no_show', true);

            $passengers = BookingPassenger::query()
                ->where('booking_id', $booking->getKey())
                ->orderBy('id')
                ->get();

            $this->postJson(
                '/api/v1/mad-trans/bookings/'.$booking->getKey().'/passengers/'.$passengers[0]->getKey().'/board'
            )->assertOk();

            $this->getJson('/api/v1/mad-trans/driver/schedules/'.$schedule->getKey())
                ->assertOk()
                ->assertJsonPath('data.boarded_count', 1)
                ->assertJsonPath('data.bookings.0.passengers.0.can_board', false)
                ->assertJsonPath('data.bookings.0.passengers.0.can_mark_no_show', false);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_other_driver_cannot_open_someone_elses_trip(): void
    {
        [$agency, $driver, $driverMembershipId] = $this->agencyWithDriver();
        [$schedule] = $this->codSchedule($agency, $driverMembershipId);

        $stranger = User::factory()->accountType(AccountType::Driver)->create(['status' => 'active']);
        $agency->members()->attach($stranger, ['role' => 'driver', 'status' => 'active', 'joined_at' => now()]);

        Sanctum::actingAs($stranger);

        $this->getJson('/api/v1/mad-trans/driver/schedules/'.$schedule->getKey())->assertForbidden();
        $this->getJson('/api/v1/mad-trans/driver/schedules')->assertOk()->assertJsonPath('data.active', []);
    }

    public function test_non_driver_account_is_outside_driver_area(): void
    {
        [$agency, , $driverMembershipId] = $this->agencyWithDriver();
        [$schedule] = $this->codSchedule($agency, $driverMembershipId);
        $owner = User::query()->findOrFail($agency->owner_user_id);

        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/mad-trans/driver/schedules/'.$schedule->getKey())->assertForbidden();
        $this->getJson('/api/v1/mad-trans/driver/schedules')->assertForbidden();
    }

    public function test_cancelled_bookings_are_not_listed_for_driver(): void
    {
        [$agency, $driver, $driverMembershipId] = $this->agencyWithDriver();
        $this->fundCollateral($agency, 30000);
        [$schedule, $originId, $destinationId] = $this->codSchedule($agency, $driverMembershipId);

        $customer = User::factory()->create(['status' => 'active']);
        $booking = app(CreateTravelBooking::class)->execute(
            $customer,
            $schedule,
            $originId,
            $destinationId,
            [['name' => 'Penumpang Batal', 'phone' => null, 'seat_number' => 'A1']],
            'cod',
            'driver-api-cancel-test',
        );
        $booking->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();

        Sanctum::actingAs($driver);

        $this->getJson('/api/v1/mad-trans/driver/schedules/'.$schedule->getKey())
            ->assertOk()
            ->assertJsonPath('data.passenger_count', 0)
            ->assertJsonPath('data.bookings', []);
    }

    private function agencyWithDriver(): array
    {
        $owner = User::factory()->accountType(AccountType::AgencyOwner)->create(['status' => 'active']);
        $driver = User::factory()->accountType(AccountType::Driver)->create(['status' => 'active']);
        $agency = Business::query()->create([
            'type' => 'agency',
            'name' => 'Travel Driver API',
            'code' => 'GM-AGENCY-DRV-API',
            'owner_user_id' => $owner->getKey(),
            'status' => 'active',
        ]);
        $agency->members()->attach($owner, ['role' => 'pemilik', 'status' => 'active', 'joined_at' => now()]);
        $agency->members()->attach($driver, ['role' => 'driver', 'status' => 'active', 'joined_at' => now()]);

        $driverMembershipId = (int) BusinessMember::query()
            ->where('business_id', $agency->getKey())
            ->where('user_id', $driver->getKey())
            ->value('id');

        return [$agency, $driver, $driverMembershipId];
    }

    private function codSchedule(Business $agency, int $driverMembershipId): array
    {
        $route = TravelRoute::query()->create([
            'business_id' => $agency->getKey(),
            'origin_city' => 'Jakarta',
            'destination_city' => 'Bandung',
            'status' => 'active',
        ]);
        $origin = RouteStop::query()->create([
            'route_id' => $route->getKey(),
            'stop_order' => 1,
            'city_name' => 'Jakarta',
            'name' => 'Terminal Lebak Bulus',
        ]);
        $destination = RouteStop::query()->create([
            'route_id' => $route->getKey(),
            'stop_order' => 2,
            'city_name' => 'Bandung',
            'name' => 'Terminal Cicaheum',
        ]);
        RoutePricing::query()->create([
            'route_id' => $route->getKey(),
            'origin_stop_id' => $origin->getKey(),
            'destination_stop_id' => $destination->getKey(),
            'price' => '120000.00',
            'is_active' => true,
        ]);
        $vehicle = Vehicle::query()->create([
            'business_id' => $agency->getKey(),
            'name' => 'Hiace Driver',
            'plate_number' => 'B 7777 DRV',
            'seat_layout' => ['A1', 'A2'],
            'capacity' => 2,
            'status' => 'active',
        ]);
        $schedule = TravelSchedule::query()->create([
            'business_id' => $agency->getKey(),
            'vehicle_id' => $vehicle->getKey(),
            'route_id' => $route->getKey(),
            'driver_id' => $driverMembershipId,
            'departure_date' => now()->addDay()->toDateString(),
            'departure_time' => '07:30:00',
            'travel_class' => 'regular',
            'price_per_seat' => '120000.00',
            'status' => 'published',
            'proposed_by' => $agency->owner_user_id,
            'allow_cod' => true,
            'payment_methods' => ['midtrans', 'cod'],
        ]);
        $schedule->stops()->createMany([
            ['route_stop_id' => $origin->getKey(), 'stop_order' => 1],
            ['route_stop_id' => $destination->getKey(), 'stop_order' => 2],
        ]);

        return [$schedule, $origin->getKey(), $destination->getKey()];
    }

    private function fundCollateral(Business $agency, int $amount): void
    {
        app(LedgerAccountSeeder::class)->ensureBusinessAccounts($agency);
        app(PostLedgerTransaction::class)->execute(new PostLedgerTransactionData(
            category: LedgerCategory::CollateralTopup,
            idempotencyKey: 'test:cod-collateral-api:'.$agency->getKey(),
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
        ));
    }
}
