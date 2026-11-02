<?php

namespace Tests\Feature;

use App\Core\Access\Infrastructure\AccessControlSeeder;
use App\Core\Ledger\Infrastructure\LedgerAccountSeeder;
use App\Models\Business;
use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use App\Models\PaymentIntent;
use App\Models\RoutePricing;
use App\Models\RouteStop;
use App\Models\TravelBooking;
use App\Models\TravelRoute;
use App\Models\TravelSchedule;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class MadTransBookingTest extends TestCase
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
            'services.midtrans.server_key' => 'sandbox-server-key-for-travel-tests',
            'services.midtrans.client_key' => 'sandbox-client-key-for-travel-tests',
            'services.midtrans.snap_url' => 'https://snap.sandbox.test/v1/transactions',
        ]);
        $this->seed(AccessControlSeeder::class);
        app(LedgerAccountSeeder::class)->run();
    }

    public function test_customer_portal_renders_seat_map_and_creates_booking(): void
    {
        [, $schedule, $origin, $destination] = $this->publishedSchedule();
        $customer = User::factory()->create(['status' => 'active']);

        $this->actingAs($customer)
            ->get('/mad-trans/schedules/'.$schedule->getKey())
            ->assertOk()
            ->assertSee('Denah kursi')
            ->assertSee('A1');

        $this->actingAs($customer)->post('/mad-trans/schedules/'.$schedule->getKey().'/bookings', [
            'booking_token' => (string) Str::uuid(),
            'origin_stop_id' => $origin->getKey(),
            'destination_stop_id' => $destination->getKey(),
            'payment_method' => 'midtrans',
            'seats' => [[
                'seat_number' => 'A1',
                'name' => 'Pemesan Web',
                'phone' => '+628111222333',
            ]],
        ])->assertRedirect();

        $booking = TravelBooking::query()->latest('id')->firstOrFail();

        $this->actingAs($customer)
            ->get('/mad-trans/bookings/'.$booking->getKey())
            ->assertOk()
            ->assertSee($booking->code)
            ->assertSee('E-Ticket');
    }

    public function test_customer_searches_schedule_books_a_unique_seat_idempotently_and_cancels(): void
    {
        [$owner, $schedule, $origin, $destination] = $this->publishedSchedule();
        $customer = User::factory()->create(['status' => 'active']);
        $tomorrow = now()->addDay()->toDateString();
        $search = $this->apiAs($customer)
            ->getJson('/api/v1/mad-trans/schedules?origin_city=Sumenep&destination_city=Surabaya&departure_date='.$tomorrow.'&passengers=1');
        $search
            ->assertOk()
            ->assertJsonPath('data.0.agency.name', 'Travel Test')
            ->assertJsonPath('data.0.available_seats', 2)
            ->assertJsonPath('data.0.price_per_seat', '150000.00');

        $payload = [
            'schedule_id' => $schedule->getKey(),
            'origin_stop_id' => $origin->getKey(),
            'destination_stop_id' => $destination->getKey(),
            'payment_method' => 'midtrans',
            'passengers' => [[
                'name' => 'Penumpang Satu',
                'phone' => '+6281234567890',
                'seat_number' => 'A1',
            ]],
        ];
        $first = $this->apiAs($customer)
            ->withHeader('Idempotency-Key', 'booking-one')
            ->postJson('/api/v1/mad-trans/bookings', $payload)
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.base_price', '150000.00')
            ->assertJsonPath('data.service_fee', '5000.00')
            ->assertJsonPath('data.platform_fee', '4500.00')
            ->assertJsonPath('data.ppn_amount', '1045.00')
            ->assertJsonPath('data.total_price', '160545.00')
            ->assertJsonPath('data.route.origin', 'Sumenep')
            ->assertJsonPath('data.route.destination', 'Surabaya')
            ->assertJsonPath('data.departure_date', $tomorrow);
        $bookingId = $first->json('data.id');

        $this->apiAs($customer)
            ->withHeader('Idempotency-Key', 'booking-one')
            ->postJson('/api/v1/mad-trans/bookings', $payload)
            ->assertCreated()
            ->assertJsonPath('data.id', $bookingId);

        $otherCustomer = User::factory()->create(['status' => 'active']);
        $this->apiAs($otherCustomer)
            ->withHeader('Idempotency-Key', 'booking-two')
            ->postJson('/api/v1/mad-trans/bookings', $payload)
            ->assertConflict();
        $this->apiAs($customer)
            ->postJson('/api/v1/mad-trans/bookings/'.$bookingId.'/cancel')
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.passengers.0.seat_reserved', false);

        $this->apiAs($otherCustomer)
            ->withHeader('Idempotency-Key', 'booking-three')
            ->postJson('/api/v1/mad-trans/bookings', $payload)
            ->assertCreated()
            ->assertJsonPath('data.passengers.0.seat_reserved', true);
    }

    public function test_unconfigured_tax_prevents_booking_without_reserving_a_seat(): void
    {
        [, $schedule, $origin, $destination] = $this->publishedSchedule();
        config(['services.mad_trans.ppn_basis_points' => null]);
        $customer = User::factory()->create(['status' => 'active']);

        $this->apiAs($customer)
            ->withHeader('Idempotency-Key', 'tax-unconfigured')
            ->postJson('/api/v1/mad-trans/bookings', [
                'schedule_id' => $schedule->getKey(),
                'origin_stop_id' => $origin->getKey(),
                'destination_stop_id' => $destination->getKey(),
                'payment_method' => 'midtrans',
                'passengers' => [['name' => 'Penumpang', 'seat_number' => 'A1']],
            ])
            ->assertStatus(503)
            ->assertJsonPath('code', 'CHECKOUT_NOT_CONFIGURED');

        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('booking_passengers', 0);
    }

    public function test_customer_cannot_read_or_cancel_another_users_booking(): void
    {
        [, $schedule, $origin, $destination] = $this->publishedSchedule();
        $customer = User::factory()->create(['status' => 'active']);
        $otherCustomer = User::factory()->create(['status' => 'active']);
        $response = $this->apiAs($customer)
            ->withHeader('Idempotency-Key', 'private-travel-booking')
            ->postJson('/api/v1/mad-trans/bookings', [
                'schedule_id' => $schedule->getKey(),
                'origin_stop_id' => $origin->getKey(),
                'destination_stop_id' => $destination->getKey(),
                'payment_method' => 'midtrans',
                'passengers' => [['name' => 'Penumpang', 'seat_number' => 'A2']],
            ])
            ->assertCreated();
        $bookingId = $response->json('data.id');

        $this->apiAs($otherCustomer)
            ->getJson('/api/v1/mad-trans/bookings/'.$bookingId)
            ->assertNotFound();
        $this->apiAs($otherCustomer)
            ->postJson('/api/v1/mad-trans/bookings/'.$bookingId.'/cancel')
            ->assertForbidden();
    }

    public function test_travel_payment_settles_exact_amount_to_agency_ledger_idempotently(): void
    {
        Notification::fake();
        Http::fake(['https://snap.sandbox.test/*' => Http::response([
            'token' => 'travel-snap-token',
            'redirect_url' => 'https://app.sandbox.midtrans.com/snap/redirect',
        ])]);
        [, $schedule, $origin, $destination] = $this->publishedSchedule();
        $customer = User::factory()->create(['status' => 'active']);
        $bookingResponse = $this->apiAs($customer)
            ->withHeader('Idempotency-Key', 'paid-booking')
            ->postJson('/api/v1/mad-trans/bookings', [
                'schedule_id' => $schedule->getKey(),
                'origin_stop_id' => $origin->getKey(),
                'destination_stop_id' => $destination->getKey(),
                'payment_method' => 'midtrans',
                'passengers' => [['name' => 'Penumpang', 'seat_number' => 'A1']],
            ])
            ->assertCreated();
        $bookingId = $bookingResponse->json('data.id');

        $this->apiAs($customer)
            ->withHeader('Idempotency-Key', 'pay-travel-booking')
            ->postJson('/api/v1/mad-trans/bookings/'.$bookingId.'/pay')
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.amount', '160545.00')
            ->assertJsonPath('data.snap_token', 'travel-snap-token');
        $payment = PaymentIntent::query()->where('booking_id', $bookingId)->firstOrFail();
        $this->assertSame('travel_booking', $payment->purpose);
        $this->assertSame($customer->getKey(), $payment->user_id);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->data()['transaction_details']['gross_amount'] === 160545
            && array_sum(array_map(
                fn (array $item): int => $item['price'] * $item['quantity'],
                $request->data()['item_details'],
            )) === 160545
        );

        $payload = $this->webhookPayload($payment);
        $this->postJson('/api/v1/webhooks/midtrans', $payload)->assertOk();
        $this->postJson('/api/v1/webhooks/midtrans', $payload)->assertOk();

        $booking = TravelBooking::query()->findOrFail($bookingId);
        $this->assertSame('paid', $booking->status);
        $this->assertSame('150000.00', $this->account('BUSINESS.PAYABLE:'.$schedule->business_id)->balance_cached);
        $this->assertSame('5000.00', $this->account('PLATFORM.REVENUE.SERVICE_FEE')->balance_cached);
        $this->assertSame('4500.00', $this->account('PLATFORM.REVENUE.PLATFORM_FEE')->balance_cached);
        $this->assertSame('1045.00', $this->account('PLATFORM.TAX_PAYABLE_PPN')->balance_cached);
        $this->assertSame(1, LedgerTransaction::query()->where('category', 'BOOKING_PAID_ONLINE')->count());
        $this->assertSame(1, DB::table('payment_webhook_events')
            ->where('payment_intent_id', $payment->getKey())
            ->count());
    }

    private function publishedSchedule(): array
    {
        $owner = User::factory()->create(['status' => 'active']);
        $agency = Business::query()->create([
            'type' => 'agency',
            'name' => 'Travel Test',
            'code' => 'GM-TRAVEL-0001',
            'owner_user_id' => $owner->getKey(),
            'status' => 'active',
        ]);
        $agency->members()->attach($owner, [
            'role' => 'pemilik',
            'status' => 'active',
            'joined_at' => now(),
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
        RoutePricing::query()->create([
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
            'status' => 'published',
            'proposed_by' => $owner->getKey(),
            'allow_cod' => false,
            'payment_methods' => ['midtrans'],
        ]);
        $schedule->stops()->createMany([
            ['route_stop_id' => $origin->getKey(), 'stop_order' => 1],
            ['route_stop_id' => $destination->getKey(), 'stop_order' => 2],
        ]);

        return [$owner, $schedule, $origin, $destination];
    }

    private function apiAs(User $user): static
    {
        Auth::forgetGuards();

        return $this->withToken($user->createToken('mad-trans-test')->plainTextToken);
    }

    private function webhookPayload(PaymentIntent $payment): array
    {
        $payload = [
            'order_id' => $payment->provider_order_id,
            'status_code' => '200',
            'gross_amount' => (string) $payment->amount,
            'transaction_status' => 'settlement',
            'transaction_id' => 'travel-midtrans-'.$payment->getKey(),
            'fraud_status' => 'accept',
        ];
        $payload['signature_key'] = hash(
            'sha512',
            $payload['order_id'].$payload['status_code'].$payload['gross_amount'].'sandbox-server-key-for-travel-tests',
        );

        return $payload;
    }

    private function account(string $code): LedgerAccount
    {
        return LedgerAccount::query()->where('code', $code)->firstOrFail();
    }
}
