<?php

namespace Tests\Feature;

use App\Core\Access\Infrastructure\AccessControlSeeder;
use App\Core\Identity\Domain\AccountType;
use App\Core\Ledger\Domain\LedgerMoney;
use App\Core\Ledger\Infrastructure\LedgerAccountSeeder;
use App\Core\MadTrans\Application\ComputePickupSurcharge;
use App\Core\MadTrans\Application\CreateTravelBooking;
use App\Core\MadTrans\Application\TravelBookingFare;
use App\Core\MadTrans\Domain\GeoPoint;
use App\Models\Business;
use App\Models\BusinessPickupPolicy;
use App\Models\RoutePricing;
use App\Models\RouteStop;
use App\Models\TravelRoute;
use App\Models\TravelSchedule;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Biaya jemput di luar titik kumpul.
 *
 * Aturannya: jarak dihitung server, gratis sampai radius, selebihnya dibulatkan
 * ke atas per kilometer, dan rupiahnya milik usaha (bukan fee platform sehingga
 * tidak ikut dihitung PPN).
 */
class PickupSurchargeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        app(LedgerAccountSeeder::class)->run();
        config([
            'services.mad_trans.service_fee' => 5000,
            'services.mad_trans.platform_fee_basis_points' => 300,
            'services.mad_trans.ppn_basis_points' => 1100,
        ]);
    }

    public function test_geo_point_parses_coordinates_and_map_links(): void
    {
        $plain = GeoPoint::fromInput('-7.2012345, 112.7012345');
        $this->assertSame(-7.2012345, $plain->latitude);
        $this->assertSame(112.7012345, $plain->longitude);

        $osm = GeoPoint::fromInput('https://www.openstreetmap.org/?mlat=-7.2&mlon=112.7#map=17/-7.2000000/112.7000000');
        $this->assertSame(-7.2, $osm->latitude);

        $google = GeoPoint::fromInput('https://www.google.com/maps/@-7.25,112.75,15z');
        $this->assertSame(-7.25, $google->latitude);
        $this->assertSame(112.75, $google->longitude);
    }

    public function test_geo_point_rejects_unreadable_input(): void
    {
        $this->expectException(InvalidArgumentException::class);
        GeoPoint::fromInput('depan masjid dekat pasar');
    }

    public function test_distance_is_computed_with_haversine(): void
    {
        // Sumenep → Surabaya kurang lebih 150 km; cukup untuk memastikan rumus wajar.
        $sumenep = GeoPoint::fromCoordinates(-7.0, 113.85);
        $surabaya = GeoPoint::fromCoordinates(-7.25, 112.75);

        $distance = $sumenep->distanceKmTo($surabaya);

        $this->assertGreaterThan(110, $distance);
        $this->assertLessThan(130, $distance);
    }

    public function test_surcharge_is_free_inside_the_radius(): void
    {
        $policy = new BusinessPickupPolicy(['free_radius_km' => 5, 'fee_per_km' => 4000]);
        $stop = GeoPoint::fromCoordinates(-7.0, 112.0);
        $customer = GeoPoint::fromCoordinates(-7.0, 112.03); // ±3,3 km

        $result = app(ComputePickupSurcharge::class)->execute($policy, $stop, $customer);

        $this->assertSame(0, $result->fee);
        $this->assertSame(0, $result->billableKm);
        $this->assertStringContainsString('radius gratis', $result->explanation());
    }

    public function test_surcharge_rounds_each_kilometre_upwards(): void
    {
        $policy = new BusinessPickupPolicy(['free_radius_km' => 5, 'fee_per_km' => 4000]);
        $stop = GeoPoint::fromCoordinates(-7.0, 112.0);
        // ±11,1 km → 6,1 km di luar radius → dibulatkan 7 km → Rp 28.000.
        $customer = GeoPoint::fromCoordinates(-7.0, 112.1);

        $result = app(ComputePickupSurcharge::class)->execute($policy, $stop, $customer);

        $this->assertGreaterThan(11.0, $result->distanceKm);
        $this->assertSame(7, $result->billableKm);
        $this->assertSame(28000, $result->fee);
    }

    public function test_zero_radius_charges_from_the_first_kilometre(): void
    {
        $policy = new BusinessPickupPolicy(['free_radius_km' => 0, 'fee_per_km' => 5000]);
        $stop = GeoPoint::fromCoordinates(-7.0, 112.0);
        $customer = GeoPoint::fromCoordinates(-7.0, 112.02); // ±2,2 km

        $result = app(ComputePickupSurcharge::class)->execute($policy, $stop, $customer);

        $this->assertSame(3, $result->billableKm);
        $this->assertSame(15000, $result->fee);
    }

    public function test_without_policy_or_point_there_is_no_charge(): void
    {
        $stop = GeoPoint::fromCoordinates(-7.0, 112.0);
        $customer = GeoPoint::fromCoordinates(-7.0, 112.5);
        $surcharge = app(ComputePickupSurcharge::class);

        $this->assertSame(0, $surcharge->execute(null, $stop, $customer)->fee);
        $this->assertSame(0, $surcharge->execute(new BusinessPickupPolicy(['free_radius_km' => 0, 'fee_per_km' => 5000]), null, $customer)->fee);
        $this->assertSame(0, $surcharge->execute(new BusinessPickupPolicy(['free_radius_km' => 0, 'fee_per_km' => 5000]), $stop, null)->fee);
    }

    public function test_pickup_fee_is_not_part_of_platform_fee_or_tax(): void
    {
        $fare = app(TravelBookingFare::class);

        $without = $fare->calculate(LedgerMoney::from('150000'), 1);
        $with = $fare->calculate(LedgerMoney::from('150000'), 1, LedgerMoney::from('28000'));

        $this->assertSame($without['platform']->toDecimal(), $with['platform']->toDecimal());
        $this->assertSame($without['tax']->toDecimal(), $with['tax']->toDecimal());
        $this->assertSame(28000, $with['pickup']->toCents() / 100);
        $this->assertSame(
            $without['total']->plus(LedgerMoney::from('28000'))->toDecimal(),
            $with['total']->toDecimal(),
        );
    }

    public function test_booking_stores_distance_and_charge_when_customer_asks_for_pickup(): void
    {
        [$customer, $schedule, $origin, $destination] = $this->bookableSchedule();

        BusinessPickupPolicy::query()->create([
            'business_id' => $schedule->business_id,
            'free_radius_km' => 5,
            'fee_per_km' => 4000,
        ]);

        $booking = app(CreateTravelBooking::class)->execute(
            $customer,
            $schedule,
            $origin->getKey(),
            $destination->getKey(),
            [['name' => 'Penumpang Jemput', 'phone' => null, 'seat_number' => 'A1']],
            'midtrans',
            'pickup-test-1',
            null,
            '-7.0, 112.1', // ±11,1 km dari titik kumpul
            'Jl. Raya Sumenep 10',
        );

        $this->assertNotNull($booking->pickup_distance_km);
        $this->assertSame('28000.00', $booking->pickup_fee);
        $this->assertSame(
            (float) $booking->base_price + 28000.0 + (float) $booking->service_fee + (float) $booking->platform_fee + (float) $booking->ppn_amount,
            (float) $booking->total_price,
        );
        $this->assertSame('Jl. Raya Sumenep 10', $booking->pickup_address);
    }

    public function test_booking_without_pickup_has_no_charge(): void
    {
        [$customer, $schedule, $origin, $destination] = $this->bookableSchedule();

        BusinessPickupPolicy::query()->create([
            'business_id' => $schedule->business_id,
            'free_radius_km' => 0,
            'fee_per_km' => 5000,
        ]);

        $booking = app(CreateTravelBooking::class)->execute(
            $customer,
            $schedule,
            $origin->getKey(),
            $destination->getKey(),
            [['name' => 'Penumpang Biasa', 'phone' => null, 'seat_number' => 'A1']],
            'midtrans',
            'pickup-test-2',
        );

        $this->assertNull($booking->pickup_distance_km);
        $this->assertSame('0.00', $booking->pickup_fee);
    }

    /**
     * @return array{0: User, 1: TravelSchedule, 2: RouteStop, 3: RouteStop}
     */
    private function bookableSchedule(): array
    {
        $owner = User::factory()->accountType(AccountType::AgencyOwner)->create(['status' => 'active']);
        $customer = User::factory()->accountType(AccountType::Customer)->create(['status' => 'active']);

        $agency = Business::query()->create([
            'type' => 'agency',
            'name' => 'Agency Jemput',
            'code' => 'GM-AGENCY-PICK1',
            'owner_user_id' => $owner->getKey(),
            'status' => 'active',
            'verified_at' => now(),
        ]);
        $agency->members()->attach($owner->getKey(), ['role' => 'pemilik', 'status' => 'active', 'joined_at' => now()]);

        $route = TravelRoute::query()->create([
            'business_id' => $agency->getKey(),
            'origin_city' => 'Sumenep',
            'destination_city' => 'Surabaya',
            'status' => 'active',
        ]);

        // Titik kumpul diberi koordinat supaya jarak bisa dihitung.
        $origin = RouteStop::query()->create([
            'route_id' => $route->getKey(),
            'stop_order' => 1,
            'city_name' => 'Sumenep',
            'name' => 'Terminal Sumenep',
            'latitude' => -7.0,
            'longitude' => 112.0,
        ]);

        $destination = RouteStop::query()->create([
            'route_id' => $route->getKey(),
            'stop_order' => 2,
            'city_name' => 'Surabaya',
            'name' => 'Terminal Bungurasih',
            'latitude' => -7.25,
            'longitude' => 112.75,
        ]);

        RoutePricing::query()->create([
            'route_id' => $route->getKey(),
            'origin_stop_id' => $origin->getKey(),
            'destination_stop_id' => $destination->getKey(),
            'price' => '150000.00',
            'is_active' => true,
        ]);

        $vehicle = Vehicle::query()->create([
            'business_id' => $agency->getKey(),
            'name' => 'Hiace Jemput',
            'plate_number' => 'M 4321 PJ',
            'seat_layout' => ['A1', 'A2'],
            'has_driver_seat' => true,
            'capacity' => 2,
            'status' => 'active',
        ]);

        $schedule = TravelSchedule::query()->create([
            'business_id' => $agency->getKey(),
            'vehicle_id' => $vehicle->getKey(),
            'route_id' => $route->getKey(),
            'departure_date' => now()->addDay()->toDateString(),
            'departure_time' => '08:00:00',
            'travel_class' => 'regular',
            'price_per_seat' => '150000.00',
            'status' => 'published',
            'proposed_by' => $owner->getKey(),
            'allow_cod' => true,
            'payment_methods' => ['midtrans', 'cod'],
        ]);

        $schedule->stops()->createMany([
            ['route_stop_id' => $origin->getKey(), 'stop_order' => 1],
            ['route_stop_id' => $destination->getKey(), 'stop_order' => 2],
        ]);

        return [$customer, $schedule, $origin, $destination];
    }
}
