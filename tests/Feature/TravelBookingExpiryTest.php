<?php

namespace Tests\Feature;

use App\Core\Access\Infrastructure\AccessControlSeeder;
use App\Core\Ledger\Infrastructure\LedgerAccountSeeder;
use App\Core\MadTrans\Application\ExpirePendingTravelBookings;
use App\Core\MadTrans\Domain\BookingStatus;
use App\Models\BookingPassenger;
use App\Models\Business;
use App\Models\PaymentIntent;
use App\Models\RoutePricing;
use App\Models\RouteStop;
use App\Models\TravelBooking;
use App\Models\TravelRoute;
use App\Models\TravelSchedule;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TravelBookingExpiryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.mad_trans.service_fee' => 5000,
            'services.mad_trans.platform_fee_basis_points' => 300,
            'services.mad_trans.ppn_basis_points' => 1100,
            'services.midtrans.payment_timeout' => 30,
            'services.midtrans.expire_grace_minutes' => 15,
        ]);
        $this->seed(AccessControlSeeder::class);
        app(LedgerAccountSeeder::class)->run();
    }

    public function test_unpaid_booking_past_its_deadline_is_expired_and_the_seat_is_released(): void
    {
        [, $schedule, $origin, $destination] = $this->publishedSchedule();
        $customer = User::factory()->create(['status' => 'active']);

        $this->actingAs($customer)
            ->post('/mad-trans/schedules/'.$schedule->getKey().'/bookings', [
                'booking_token' => (string) Str::uuid(),
                'origin_stop_id' => $origin->getKey(),
                'destination_stop_id' => $destination->getKey(),
                'payment_method' => 'midtrans',
                'seats' => [['seat_number' => 'A1', 'name' => 'Tidak Jadi Bayar']],
            ])
            ->assertSessionHasNoErrors();

        $booking = TravelBooking::query()->latest('id')->firstOrFail();
        $this->assertSame(BookingStatus::Pending->value, $booking->status);

        // Belum lewat batas waktu: tidak boleh dilepas.
        $early = app(ExpirePendingTravelBookings::class)->execute();
        $this->assertSame(0, $early['expired']);

        $booking->forceFill(['expires_at' => now()->subMinutes(30)])->save();

        $this->artisan('mad-trans:expire-bookings')->assertExitCode(0);

        $booking->refresh();
        $this->assertSame(BookingStatus::Expired->value, $booking->status);
        $this->assertNotNull($booking->cancelled_at);

        $passenger = BookingPassenger::query()->where('booking_id', $booking->getKey())->firstOrFail();
        $this->assertNull($passenger->seat_number);
        $this->assertNotNull($passenger->seat_released_at);

        // Kursi yang dilepas bisa dipesan lagi.
        $this->actingAs($customer)
            ->post('/mad-trans/schedules/'.$schedule->getKey().'/bookings', [
                'booking_token' => (string) Str::uuid(),
                'origin_stop_id' => $origin->getKey(),
                'destination_stop_id' => $destination->getKey(),
                'payment_method' => 'midtrans',
                'seats' => [['seat_number' => 'A1', 'name' => 'Pemesan Baru']],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            2,
            TravelBooking::query()->where('schedule_id', $schedule->getKey())->count(),
        );
    }

    public function test_booking_with_a_payment_attempt_is_left_for_the_gateway_to_settle(): void
    {
        [, $schedule, $origin, $destination] = $this->publishedSchedule();
        $customer = User::factory()->create(['status' => 'active']);

        $this->actingAs($customer)
            ->post('/mad-trans/schedules/'.$schedule->getKey().'/bookings', [
                'booking_token' => (string) Str::uuid(),
                'origin_stop_id' => $origin->getKey(),
                'destination_stop_id' => $destination->getKey(),
                'payment_method' => 'midtrans',
                'seats' => [['seat_number' => 'A2', 'name' => 'Sudah Mulai Bayar']],
            ])
            ->assertSessionHasNoErrors();

        $booking = TravelBooking::query()->latest('id')->firstOrFail();
        $booking->forceFill([
            'expires_at' => now()->subHours(3),
            'payment_intent_id' => null,
        ])->save();

        // Pemesanan dengan percobaan pembayaran tidak disentuh scheduler.
        $intent = PaymentIntent::query()->create([
            'code' => 'MT-PY-TEST-EXPIRE',
            'provider_order_id' => 'MT-PY-EXP-1',
            'purpose' => 'travel_booking',
            'booking_id' => $booking->getKey(),
            'user_id' => $customer->getKey(),
            'business_id' => $schedule->business_id,
            'amount' => $booking->total_price,
            'currency' => 'IDR',
            'status' => 'pending',
            'idempotency_key' => 'test-expire-intent-1',
            'request_hash' => hash('sha256', 'test-expire-intent-1'),
            'expires_at' => now()->addMinutes(30),
        ]);
        $booking->forceFill(['payment_intent_id' => $intent->getKey()])->save();

        $result = app(ExpirePendingTravelBookings::class)->execute();
        $this->assertSame(0, $result['expired']);
        $this->assertSame(BookingStatus::Pending->value, $booking->fresh()->status);
    }

    /**
     * @return array{0: Business, 1: TravelSchedule, 2: RouteStop, 3: RouteStop}
     */
    private function publishedSchedule(): array
    {
        $owner = User::factory()->create(['status' => 'active']);
        $agency = Business::query()->create([
            'type' => 'agency',
            'name' => 'Travel Expiry',
            'code' => 'GM-TRAVEL-EXP1',
            'owner_user_id' => $owner->getKey(),
            'status' => 'active',
        ]);
        $agency->members()->attach($owner, ['role' => 'pemilik', 'status' => 'active', 'joined_at' => now()]);

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
        RoutePricing::query()->create([
            'route_id' => $route->getKey(),
            'origin_stop_id' => $origin->getKey(),
            'destination_stop_id' => $destination->getKey(),
            'price' => '100000.00',
            'is_active' => true,
        ]);
        $vehicle = Vehicle::query()->create([
            'business_id' => $agency->getKey(),
            'name' => 'Hiace Expiry',
            'plate_number' => 'M 3333 EXP',
            'seat_layout' => ['A1', 'A2'],
            'capacity' => 2,
            'status' => 'active',
        ]);
        $schedule = TravelSchedule::query()->create([
            'business_id' => $agency->getKey(),
            'vehicle_id' => $vehicle->getKey(),
            'route_id' => $route->getKey(),
            'departure_date' => now()->addDays(2)->toDateString(),
            'departure_time' => '07:00:00',
            'travel_class' => 'regular',
            'price_per_seat' => '100000.00',
            'status' => 'published',
            'proposed_by' => $owner->getKey(),
            'allow_cod' => false,
            'payment_methods' => ['midtrans'],
        ]);
        $schedule->stops()->createMany([
            ['route_stop_id' => $origin->getKey(), 'stop_order' => 1],
            ['route_stop_id' => $destination->getKey(), 'stop_order' => 2],
        ]);

        return [$agency, $schedule, $origin, $destination];
    }
}
