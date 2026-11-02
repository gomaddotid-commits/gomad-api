<?php

namespace Tests\Feature;

use App\Core\Access\Infrastructure\AccessControlSeeder;
use App\Core\MadTrans\Domain\BookingStatus;
use App\Core\MadTrans\Domain\ScheduleStatus;
use App\Models\Business;
use App\Models\RoutePricing;
use App\Models\RouteStop;
use App\Models\TravelBooking;
use App\Models\TravelRoute;
use App\Models\TravelSchedule;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MadTransSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_mad_trans_schema_supports_agency_routes_schedules_and_unique_active_seats(): void
    {
        $owner = User::factory()->create();
        $customer = User::factory()->create();
        $agency = Business::query()->create([
            'type' => 'agency',
            'name' => 'Travel Test',
            'code' => 'GM-TRAVEL-0001',
            'owner_user_id' => $owner->getKey(),
            'status' => 'active',
        ]);
        $route = TravelRoute::query()->create([
            'business_id' => $agency->getKey(),
            'origin_city' => 'Sumenep',
            'destination_city' => 'Surabaya',
            'status' => 'active',
        ]);
        $origin = RouteStop::query()->create([
            'route_id' => $route->getKey(),
            'stop_order' => 1,
            'city_name' => 'Sumenep',
            'name' => 'Terminal Sumenep',
        ]);
        $destination = RouteStop::query()->create([
            'route_id' => $route->getKey(),
            'stop_order' => 2,
            'city_name' => 'Surabaya',
            'name' => 'Terminal Bungurasih',
        ]);
        $price = RoutePricing::query()->create([
            'route_id' => $route->getKey(),
            'origin_stop_id' => $origin->getKey(),
            'destination_stop_id' => $destination->getKey(),
            'price' => '150000.00',
            'is_active' => true,
        ]);
        $vehicle = Vehicle::query()->create([
            'business_id' => $agency->getKey(),
            'name' => 'Hiace',
            'plate_number' => 'M 1234 AB',
            'seat_layout' => ['A1', 'A2'],
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
            'status' => ScheduleStatus::Published->value,
            'proposed_by' => $owner->getKey(),
            'allow_cod' => false,
            'payment_methods' => ['midtrans'],
        ]);
        $bookingAttributes = [
            'schedule_id' => $schedule->getKey(),
            'customer_id' => $customer->getKey(),
            'origin_stop_id' => $origin->getKey(),
            'destination_stop_id' => $destination->getKey(),
            'route_pricing_id' => $price->getKey(),
            'total_passengers' => 1,
            'base_price' => '150000.00',
            'service_fee' => '5000.00',
            'platform_fee' => '4500.00',
            'discount_amount' => '0.00',
            'ppn_amount' => '1045.00',
            'total_price' => '160545.00',
            'payment_method' => 'midtrans',
            'status' => BookingStatus::Pending->value,
            'request_hash' => str_repeat('a', 64),
            'expires_at' => now()->addMinutes(15),
        ];
        $firstBooking = TravelBooking::query()->create([
            ...$bookingAttributes,
            'code' => 'MT-TR-2610-0001',
            'idempotency_key' => 'booking-seat-1',
        ]);
        $secondBooking = TravelBooking::query()->create([
            ...$bookingAttributes,
            'code' => 'MT-TR-2610-0002',
            'idempotency_key' => 'booking-seat-2',
        ]);
        $firstBooking->passengers()->create([
            'schedule_id' => $schedule->getKey(),
            'name' => 'Penumpang Satu',
            'seat_number' => 'A1',
            'seat_snapshot' => 'A1',
        ]);

        // Kursi yang sama boleh dipakai ulang pada segmen lain; penjagaan
        // tumpang-tindih segmen dilakukan di CreateTravelBooking, bukan di skema.
        $secondBooking->passengers()->create([
            'schedule_id' => $schedule->getKey(),
            'name' => 'Penumpang Dua',
            'seat_number' => 'A1',
            'seat_snapshot' => 'A1',
        ]);

        $this->assertDatabaseCount('booking_passengers', 2);
        $this->assertSame(
            2,
            $firstBooking->passengers()->where('seat_number', 'A1')->count()
                + $secondBooking->passengers()->where('seat_number', 'A1')->count(),
        );
    }

    public function test_cancelled_booking_can_release_its_seat_without_losing_the_snapshot(): void
    {
        $owner = User::factory()->create();
        $customer = User::factory()->create();
        $agency = Business::query()->create([
            'type' => 'agency',
            'name' => 'Travel Test',
            'code' => 'GM-TRAVEL-0001',
            'owner_user_id' => $owner->getKey(),
            'status' => 'active',
        ]);
        $route = TravelRoute::query()->create([
            'business_id' => $agency->getKey(),
            'origin_city' => 'Sumenep',
            'destination_city' => 'Surabaya',
            'status' => 'active',
        ]);
        $origin = RouteStop::query()->create([
            'route_id' => $route->getKey(),
            'stop_order' => 1,
            'city_name' => 'Sumenep',
            'name' => 'Terminal Sumenep',
        ]);
        $destination = RouteStop::query()->create([
            'route_id' => $route->getKey(),
            'stop_order' => 2,
            'city_name' => 'Surabaya',
            'name' => 'Terminal Bungurasih',
        ]);
        $price = RoutePricing::query()->create([
            'route_id' => $route->getKey(),
            'origin_stop_id' => $origin->getKey(),
            'destination_stop_id' => $destination->getKey(),
            'price' => '100000.00',
            'is_active' => true,
        ]);
        $vehicle = Vehicle::query()->create([
            'business_id' => $agency->getKey(),
            'name' => 'Hiace',
            'plate_number' => 'M 1234 AB',
            'seat_layout' => ['A1'],
            'capacity' => 1,
            'status' => 'active',
        ]);
        $schedule = TravelSchedule::query()->create([
            'business_id' => $agency->getKey(),
            'vehicle_id' => $vehicle->getKey(),
            'route_id' => $route->getKey(),
            'departure_date' => now()->addDay()->toDateString(),
            'departure_time' => '08:00:00',
            'travel_class' => 'regular',
            'price_per_seat' => '100000.00',
            'status' => ScheduleStatus::Published->value,
            'proposed_by' => $owner->getKey(),
            'allow_cod' => false,
            'payment_methods' => ['midtrans'],
        ]);
        $booking = TravelBooking::query()->create([
            'code' => 'MT-TR-2610-0001',
            'schedule_id' => $schedule->getKey(),
            'customer_id' => $customer->getKey(),
            'origin_stop_id' => $origin->getKey(),
            'destination_stop_id' => $destination->getKey(),
            'route_pricing_id' => $price->getKey(),
            'total_passengers' => 1,
            'base_price' => '100000.00',
            'service_fee' => '5000.00',
            'platform_fee' => '3000.00',
            'discount_amount' => '0.00',
            'ppn_amount' => '880.00',
            'total_price' => '108880.00',
            'payment_method' => 'midtrans',
            'status' => BookingStatus::Cancelled->value,
            'idempotency_key' => 'cancelled-seat',
            'request_hash' => str_repeat('b', 64),
            'expires_at' => now()->addMinutes(15),
        ]);

        $booking->passengers()->create([
            'schedule_id' => $schedule->getKey(),
            'name' => 'Penumpang Satu',
            'seat_number' => null,
            'seat_snapshot' => 'A1',
            'seat_released_at' => now(),
        ]);
        $this->assertDatabaseHas('booking_passengers', [
            'booking_id' => $booking->getKey(),
            'seat_number' => null,
            'seat_snapshot' => 'A1',
        ]);
    }
}
