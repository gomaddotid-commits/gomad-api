<?php

namespace Tests\Feature;

use App\Core\Access\Infrastructure\AccessControlSeeder;
use App\Core\Ledger\Infrastructure\LedgerAccountSeeder;
use App\Core\Loyalty\Application\AwardLoyaltyPoints;
use App\Core\Loyalty\Application\LoyaltySummary;
use App\Models\Business;
use App\Models\LoyaltyEvent;
use App\Models\PaymentIntent;
use App\Models\RoutePricing;
use App\Models\RouteStop;
use App\Models\TravelBooking;
use App\Models\TravelRoute;
use App\Models\TravelSchedule;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class LoyaltyTest extends TestCase
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
            'services.midtrans.server_key' => 'sandbox-server-key-for-loyalty-tests',
            'services.midtrans.client_key' => 'sandbox-client-key-for-loyalty-tests',
            'services.midtrans.snap_url' => 'https://snap.sandbox.test/v1/transactions',
            'services.loyalty.points_per_amount' => 10000,
        ]);
        $this->seed(AccessControlSeeder::class);
        app(LedgerAccountSeeder::class)->run();
    }

    public function test_points_are_awarded_once_per_paid_transaction(): void
    {
        [, $schedule, $origin, $destination] = $this->publishedSchedule();
        $customer = User::factory()->create(['status' => 'active']);

        $this->actingAs($customer)
            ->post('/mad-trans/schedules/'.$schedule->getKey().'/bookings', [
                'booking_token' => (string) Str::uuid(),
                'origin_stop_id' => $origin->getKey(),
                'destination_stop_id' => $destination->getKey(),
                'payment_method' => 'midtrans',
                'seats' => [['seat_number' => 'A1', 'name' => 'Pelanggan Setia']],
            ])
            ->assertSessionHasNoErrors();

        $booking = TravelBooking::query()->latest('id')->firstOrFail();

        Http::fake(['https://snap.sandbox.test/*' => Http::response([
            'token' => 'snap-token-loyalty',
            'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/snap-token-loyalty',
        ], 201)]);

        Auth::forgetGuards();
        $this->withToken($customer->createToken('loyalty-test')->plainTextToken)
            ->withHeader('Idempotency-Key', 'pay-loyalty')
            ->postJson('/api/v1/mad-trans/bookings/'.$booking->getKey().'/pay')
            ->assertSuccessful();

        $payment = PaymentIntent::query()->where('booking_id', $booking->getKey())->latest('id')->firstOrFail();
        $payload = [
            'order_id' => $payment->provider_order_id,
            'status_code' => '200',
            'gross_amount' => (string) $payment->amount,
            'transaction_status' => 'settlement',
            'transaction_id' => 'loyalty-midtrans-'.$payment->getKey(),
            'fraud_status' => 'accept',
        ];
        $payload['signature_key'] = hash(
            'sha512',
            $payload['order_id'].$payload['status_code'].$payload['gross_amount'].'sandbox-server-key-for-loyalty-tests',
        );
        $this->postJson('/api/v1/webhooks/midtrans', $payload)->assertOk();

        // Harga tiket 150.000 → 15 poin (Rp 10.000 per poin).
        $this->assertSame(15, (int) $customer->fresh()->points_cached);
        $this->assertSame(1, LoyaltyEvent::query()->where('user_id', $customer->getKey())->count());

        // Webhook duplikat tidak menambah poin lagi.
        $this->postJson('/api/v1/webhooks/midtrans', $payload)->assertOk();
        $this->assertSame(15, (int) $customer->fresh()->points_cached);
        $this->assertSame(1, LoyaltyEvent::query()->where('user_id', $customer->getKey())->count());
    }

    public function test_level_is_derived_from_points_and_progress_counts_down(): void
    {
        $customer = User::factory()->create(['status' => 'active', 'points_cached' => 150]);
        $summary = app(LoyaltySummary::class)->for($customer);

        $this->assertSame('silver', $summary['level']['code']);
        $this->assertSame('gold', $summary['next']['code']);
        $this->assertSame(150, $summary['to_next']);
        $this->assertSame(25, $summary['progress_percent']);

        $this->actingAs($customer)
            ->get('/level')
            ->assertOk()
            ->assertSee('Silver')
            ->assertSee('Misi')
            ->assertSee('150');
    }

    public function test_award_helper_is_idempotent_per_reference(): void
    {
        $customer = User::factory()->create(['status' => 'active']);
        $award = app(AwardLoyaltyPoints::class);

        $award->execute($customer, LoyaltyEvent::KIND_RENTAL_PAID, 'rental', 99, 30000000);
        $award->execute($customer, LoyaltyEvent::KIND_RENTAL_PAID, 'rental', 99, 30000000);

        $this->assertSame(30, (int) $customer->fresh()->points_cached);
        $this->assertSame(1, LoyaltyEvent::query()->count());
    }

    /**
     * @return array{0: Business, 1: TravelSchedule, 2: RouteStop, 3: RouteStop}
     */
    private function publishedSchedule(): array
    {
        $owner = User::factory()->create(['status' => 'active']);
        $agency = Business::query()->create([
            'type' => 'agency',
            'name' => 'Travel Loyalty',
            'code' => 'GM-TRAVEL-LYL1',
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
            'price' => '150000.00',
            'is_active' => true,
        ]);
        $vehicle = Vehicle::query()->create([
            'business_id' => $agency->getKey(),
            'name' => 'Hiace Loyalty',
            'plate_number' => 'M 8888 LYL',
            'seat_layout' => ['A1', 'A2'],
            'capacity' => 2,
            'status' => 'active',
        ]);
        $schedule = TravelSchedule::query()->create([
            'business_id' => $agency->getKey(),
            'vehicle_id' => $vehicle->getKey(),
            'route_id' => $route->getKey(),
            'departure_date' => now()->addDay()->toDateString(),
            'departure_time' => '10:00:00',
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

        return [$agency, $schedule, $origin, $destination];
    }
}
