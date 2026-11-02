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
use App\Models\TravelBooking;
use App\Models\TravelRoute;
use App\Models\TravelSchedule;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MadTransDriverPortalTest extends TestCase
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

    public function test_assigned_driver_sees_trip_and_handles_cod_passengers(): void
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
            'driver-portal-test',
        );

        $this->actingAs($driver)
            ->get('/mad-trans/driver')
            ->assertOk()
            ->assertSee('Jakarta → Bandung');

        $this->actingAs($driver)
            ->get('/mad-trans/driver/schedules/'.$schedule->getKey())
            ->assertOk()
            ->assertSee('Penumpang Naik')
            ->assertSee('Tombol boarding aktif setelah perjalanan dimulai.');

        Carbon::setTestNow(now()->addDay()->setTime(8, 0));

        try {
            $this->actingAs($driver)
                ->post('/mad-trans/driver/schedules/'.$schedule->getKey().'/start')
                ->assertSessionHasNoErrors();

            $this->assertSame('in_progress', $schedule->fresh()->status);

            $this->actingAs($driver)
                ->get('/mad-trans/driver/schedules/'.$schedule->getKey())
                ->assertOk()
                ->assertSee('Konfirmasi naik');

            $passengers = BookingPassenger::query()
                ->where('booking_id', $booking->getKey())
                ->orderBy('id')
                ->get();

            $this->actingAs($driver)
                ->post('/mad-trans/driver/bookings/'.$booking->getKey().'/passengers/'.$passengers[0]->getKey().'/board')
                ->assertSessionHasNoErrors();

            $this->assertNotNull($passengers[0]->fresh()->boarded_at);

            $this->actingAs($driver)
                ->post('/mad-trans/driver/bookings/'.$booking->getKey().'/passengers/'.$passengers[1]->getKey().'/no-show')
                ->assertSessionHasNoErrors();

            $this->assertNotNull($passengers[1]->fresh()->no_show_at);

            $this->actingAs($driver)
                ->post('/mad-trans/driver/schedules/'.$schedule->getKey().'/finish')
                ->assertSessionHasNoErrors();

            $this->assertSame('completed', $schedule->fresh()->status);
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame('completed', TravelBooking::query()->findOrFail($booking->getKey())->status);
    }

    public function test_owner_without_driver_assignment_cannot_open_driver_trip(): void
    {
        [$agency, , $driverMembershipId] = $this->agencyWithDriver();
        [$schedule] = $this->codSchedule($agency, $driverMembershipId);
        $owner = User::query()->findOrFail($agency->owner_user_id);

        $this->actingAs($owner)
            ->get('/mad-trans/driver/schedules/'.$schedule->getKey())
            ->assertForbidden();
    }

    private function agencyWithDriver(): array
    {
        $owner = User::factory()->accountType(AccountType::AgencyOwner)->create(['status' => 'active']);
        $driver = User::factory()->accountType(AccountType::Driver)->create(['status' => 'active']);
        $agency = Business::query()->create([
            'type' => 'agency',
            'name' => 'Travel Driver Test',
            'code' => 'GM-AGENCY-DRV1',
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
        ));
    }
}
