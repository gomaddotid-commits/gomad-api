<?php

namespace Tests\Feature;

use App\Core\Access\Infrastructure\AccessControlSeeder;
use App\Core\Identity\Domain\AccountType;
use App\Core\Ledger\Infrastructure\LedgerAccountSeeder;
use App\Core\Settlement\Application\SettlementBreakdown;
use App\Models\Business;
use App\Models\LedgerAccount;
use App\Models\PaymentIntent;
use App\Models\Promo;
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

class SettlementBreakdownTest extends TestCase
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
            'services.midtrans.server_key' => 'sandbox-server-key-for-settlement-tests',
            'services.midtrans.client_key' => 'sandbox-client-key-for-settlement-tests',
            'services.midtrans.snap_url' => 'https://snap.sandbox.test/v1/transactions',
        ]);
        $this->seed(AccessControlSeeder::class);
        app(LedgerAccountSeeder::class)->run();
    }

    public function test_breakdown_columns_add_up_to_the_agency_net_that_lands_in_the_ledger(): void
    {
        [$agency, $schedule, $origin, $destination] = $this->publishedSchedule();
        $customer = User::factory()->create(['status' => 'active']);
        $owner = User::query()->findOrFail($agency->owner_user_id);

        $this->actingAs($customer)
            ->post('/mad-trans/schedules/'.$schedule->getKey().'/bookings', [
                'booking_token' => (string) Str::uuid(),
                'origin_stop_id' => $origin->getKey(),
                'destination_stop_id' => $destination->getKey(),
                'payment_method' => 'midtrans',
                'seats' => [['seat_number' => 'A1', 'name' => 'Pembeli Settle']],
            ])
            ->assertSessionHasNoErrors();

        $booking = TravelBooking::query()->latest('id')->firstOrFail();
        $this->settle($customer, $booking);

        $breakdown = app(SettlementBreakdown::class)->forBusiness($agency);
        $this->assertCount(1, $breakdown['rows']);

        $row = $breakdown['rows'][0];
        $this->assertSame($booking->code, $row['code']);
        $this->assertSame('150000.00', $row['price']);
        $this->assertSame('9500.00', $row['fee']);
        $this->assertSame('1045.00', $row['ppn']);
        $this->assertSame('0.00', $row['discount']);
        $this->assertSame('150000.00', $row['net']);

        // Hak bersih agency di rincian harus sama dengan yang masuk ke ledger.
        $this->assertSame(
            $row['net'],
            (string) LedgerAccount::query()->where('code', 'BUSINESS.PAYABLE:'.$agency->getKey())->firstOrFail()->balance_cached,
        );

        $this->actingAs($owner)
            ->get('/mad-trans/agency/'.$agency->getKey().'/laporan')
            ->assertOk()
            ->assertSee('Rincian settlement')
            ->assertSee($booking->code);
    }

    public function test_business_borne_discount_reduces_the_agency_net_in_the_breakdown(): void
    {
        [$agency, $schedule, $origin, $destination] = $this->publishedSchedule();
        $customer = User::factory()->create(['status' => 'active']);

        Promo::query()->create([
            'code' => 'POTONG20',
            'name' => 'Potongan agency',
            'owner_type' => Promo::OWNER_BUSINESS,
            'business_id' => $agency->getKey(),
            'cost_bearer' => 'business',
            'discount_type' => 'amount',
            'discount_value' => '20000.00',
            'min_spend' => '0.00',
            'applies_to' => 'all',
            'per_user_limit' => 1,
            'status' => 'active',
        ]);

        $this->actingAs($customer)
            ->post('/mad-trans/schedules/'.$schedule->getKey().'/bookings', [
                'booking_token' => (string) Str::uuid(),
                'origin_stop_id' => $origin->getKey(),
                'destination_stop_id' => $destination->getKey(),
                'payment_method' => 'midtrans',
                'promo_code' => 'POTONG20',
                'seats' => [['seat_number' => 'A2', 'name' => 'Pembeli Diskon']],
            ])
            ->assertSessionHasNoErrors();

        $booking = TravelBooking::query()->latest('id')->firstOrFail();
        $this->settle($customer, $booking);

        $row = app(SettlementBreakdown::class)->forBusiness($agency)['rows'][0];
        $this->assertSame('20000.00', $row['discount']);
        $this->assertSame('130000.00', $row['net']);
    }

    private function settle(User $customer, TravelBooking $booking): void
    {
        Http::fake(['https://snap.sandbox.test/*' => Http::response([
            'token' => 'snap-token-settlement',
            'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/snap-token-settlement',
        ], 201)]);

        Auth::forgetGuards();
        $this->withToken($customer->createToken('settlement-test')->plainTextToken)
            ->withHeader('Idempotency-Key', 'pay-settlement-'.$booking->getKey())
            ->postJson('/api/v1/mad-trans/bookings/'.$booking->getKey().'/pay')
            ->assertSuccessful();

        $payment = PaymentIntent::query()->where('booking_id', $booking->getKey())->latest('id')->firstOrFail();
        $payload = [
            'order_id' => $payment->provider_order_id,
            'status_code' => '200',
            'gross_amount' => (string) $payment->amount,
            'transaction_status' => 'settlement',
            'transaction_id' => 'settlement-midtrans-'.$payment->getKey(),
            'fraud_status' => 'accept',
        ];
        $payload['signature_key'] = hash(
            'sha512',
            $payload['order_id'].$payload['status_code'].$payload['gross_amount'].'sandbox-server-key-for-settlement-tests',
        );
        $this->postJson('/api/v1/webhooks/midtrans', $payload)->assertOk();
    }

    /**
     * @return array{0: Business, 1: TravelSchedule, 2: RouteStop, 3: RouteStop}
     */
    private function publishedSchedule(): array
    {
        $owner = User::factory()->accountType(AccountType::AgencyOwner)->create(['status' => 'active']);
        $agency = Business::query()->create([
            'type' => 'agency',
            'name' => 'Travel Settlement',
            'code' => 'GM-TRAVEL-STL1',
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
            'name' => 'Hiace Settlement',
            'plate_number' => 'M 6666 STL',
            'seat_layout' => ['A1', 'A2'],
            'capacity' => 2,
            'status' => 'active',
        ]);
        $schedule = TravelSchedule::query()->create([
            'business_id' => $agency->getKey(),
            'vehicle_id' => $vehicle->getKey(),
            'route_id' => $route->getKey(),
            'departure_date' => now()->addDay()->toDateString(),
            'departure_time' => '11:00:00',
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
