<?php

namespace Tests\Feature;

use App\Core\Access\Infrastructure\AccessControlSeeder;
use App\Core\Identity\Domain\AccountType;
use App\Core\Ledger\Infrastructure\LedgerAccountSeeder;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use App\Models\PaymentIntent;
use App\Models\Promo;
use App\Models\PromoRedemption;
use App\Models\Rental;
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

class PromoTest extends TestCase
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
            'services.midtrans.server_key' => 'sandbox-server-key-for-promo-tests',
            'services.midtrans.client_key' => 'sandbox-client-key-for-promo-tests',
            'services.midtrans.snap_url' => 'https://snap.sandbox.test/v1/transactions',
        ]);
        $this->seed(AccessControlSeeder::class);
        app(LedgerAccountSeeder::class)->run();
    }

    public function test_platform_borne_promo_does_not_reduce_the_agency_share(): void
    {
        [$agency, $schedule, $origin, $destination] = $this->publishedSchedule();
        $customer = User::factory()->create(['status' => 'active']);

        $promo = $this->promo([
            'code' => 'PLATFORM10',
            'owner_type' => Promo::OWNER_PLATFORM,
            'business_id' => null,
            'cost_bearer' => 'platform',
            'discount_type' => 'percent',
            'discount_value' => '10.00',
            'max_discount' => '20000.00',
        ]);

        $booking = $this->book($customer, $schedule, $origin, $destination, 'PLATFORM10');

        // base 150.000 → diskon 10% = 15.000 (di bawah batas 20.000)
        $this->assertSame('15000.00', $booking->discount_amount);
        $this->assertSame($promo->getKey(), (int) $booking->promo_id);
        $this->assertSame('platform', $booking->discount_bearer);
        $this->assertSame('145545.00', $booking->total_price);

        $this->settle($customer, $booking);

        $this->assertSame('150000.00', $this->account('BUSINESS.PAYABLE:'.$agency->getKey()));
        $this->assertSame('15000.00', $this->account('PLATFORM.EXPENSE.PROMO'));
        $this->assertSame('145545.00', $this->account('PLATFORM.CASH'));
        $this->assertSame(
            1,
            LedgerTransaction::query()->where('category', 'PROMO_PLATFORM_BORNE')->count(),
        );

        $redemption = PromoRedemption::query()->firstOrFail();
        $this->assertSame('15000.00', $redemption->discount_amount);
        $this->assertSame('platform', $redemption->cost_bearer);
        $this->assertSame(1, (int) $promo->fresh()->used_count);
    }

    public function test_business_borne_promo_reduces_the_agency_share(): void
    {
        [$agency, $schedule, $origin, $destination] = $this->publishedSchedule();
        $customer = User::factory()->create(['status' => 'active']);

        $this->promo([
            'code' => 'AGENCY20',
            'owner_type' => Promo::OWNER_BUSINESS,
            'business_id' => $agency->getKey(),
            'cost_bearer' => 'business',
            'discount_type' => 'amount',
            'discount_value' => '20000.00',
        ]);

        $booking = $this->book($customer, $schedule, $origin, $destination, 'AGENCY20');

        $this->assertSame('20000.00', $booking->discount_amount);
        $this->assertSame('business', $booking->discount_bearer);
        $this->assertSame('140545.00', $booking->total_price);

        $this->settle($customer, $booking);

        // Agency menanggung diskon: bagiannya 150.000 − 20.000.
        $this->assertSame('130000.00', $this->account('BUSINESS.PAYABLE:'.$agency->getKey()));
        $this->assertSame('0.00', $this->account('PLATFORM.EXPENSE.PROMO'));
        $this->assertSame('140545.00', $this->account('PLATFORM.CASH'));
    }

    public function test_a_customer_cannot_reuse_the_same_promo_code(): void
    {
        [$agency, $schedule, $origin, $destination] = $this->publishedSchedule();
        $customer = User::factory()->create(['status' => 'active']);

        $this->promo([
            'code' => 'SEKALI50',
            'owner_type' => Promo::OWNER_PLATFORM,
            'business_id' => null,
            'cost_bearer' => 'platform',
            'discount_type' => 'amount',
            'discount_value' => '50000.00',
        ]);

        $this->book($customer, $schedule, $origin, $destination, 'SEKALI50');

        $this->actingAs($customer)
            ->post('/mad-trans/schedules/'.$schedule->getKey().'/bookings', [
                'booking_token' => (string) Str::uuid(),
                'origin_stop_id' => $origin->getKey(),
                'destination_stop_id' => $destination->getKey(),
                'payment_method' => 'midtrans',
                'promo_code' => 'SEKALI50',
                'seats' => [['seat_number' => 'A2', 'name' => 'Pemesan Kedua']],
            ])
            ->assertSessionHasErrors('promo_code');

        $this->assertSame(1, PromoRedemption::query()->count());
    }

    public function test_promo_is_rejected_for_cash_on_delivery_and_unknown_codes(): void
    {
        [$agency, $schedule, $origin, $destination] = $this->publishedSchedule();
        $schedule->forceFill(['allow_cod' => true, 'payment_methods' => ['midtrans', 'cod']])->save();
        $customer = User::factory()->create(['status' => 'active']);

        $this->promo([
            'code' => 'DISC10',
            'owner_type' => Promo::OWNER_PLATFORM,
            'business_id' => null,
            'cost_bearer' => 'platform',
            'discount_type' => 'percent',
            'discount_value' => '10.00',
        ]);

        $this->actingAs($customer)
            ->post('/mad-trans/schedules/'.$schedule->getKey().'/bookings', [
                'booking_token' => (string) Str::uuid(),
                'origin_stop_id' => $origin->getKey(),
                'destination_stop_id' => $destination->getKey(),
                'payment_method' => 'cod',
                'promo_code' => 'DISC10',
                'seats' => [['seat_number' => 'A1', 'name' => 'Pemesan COD']],
            ])
            ->assertSessionHasErrors('promo_code');

        $this->actingAs($customer)
            ->post('/mad-trans/schedules/'.$schedule->getKey().'/bookings', [
                'booking_token' => (string) Str::uuid(),
                'origin_stop_id' => $origin->getKey(),
                'destination_stop_id' => $destination->getKey(),
                'payment_method' => 'midtrans',
                'promo_code' => 'TIDAKADA',
                'seats' => [['seat_number' => 'A1', 'name' => 'Pemesan Salah Kode']],
            ])
            ->assertSessionHasErrors('promo_code');

        $this->assertSame(0, TravelBooking::query()->count());
    }

    public function test_rental_promo_reduces_the_total_and_is_borne_by_the_agency(): void
    {
        [$agency] = $this->publishedSchedule();
        $customer = User::factory()->create(['status' => 'active']);
        $owner = User::query()->findOrFail($agency->owner_user_id);

        $this->promo([
            'code' => 'SEWA50',
            'owner_type' => Promo::OWNER_BUSINESS,
            'business_id' => $agency->getKey(),
            'cost_bearer' => 'business',
            'discount_type' => 'amount',
            'discount_value' => '50000.00',
            'applies_to' => 'rental',
        ]);

        $driverUser = User::factory()->create(['status' => 'active']);
        $agency->members()->attach($driverUser, ['role' => 'driver', 'status' => 'active', 'joined_at' => now()]);
        $driver = BusinessMember::query()
            ->where('business_id', $agency->getKey())
            ->where('user_id', $driverUser->getKey())
            ->firstOrFail();

        $vehicle = Vehicle::query()->create([
            'business_id' => $agency->getKey(),
            'name' => 'Avanza Promo',
            'plate_number' => 'B 5555 PRM',
            'seat_layout' => ['A1', 'A2'],
            'capacity' => 2,
            'rental_daily_price' => '300000.00',
            'rental_driver_daily_fee' => '100000.00',
            'rental_deposit' => '200000.00',
            'status' => 'active',
        ]);

        $this->actingAs($customer)
            ->post('/mad-trans/rental/'.$agency->getKey(), [
                'rental_type' => 'with_driver',
                'vehicle_id' => $vehicle->getKey(),
                'driver_membership_id' => $driver->getKey(),
                'start_at' => now()->addDays(3)->setTime(8, 0)->format('Y-m-d\TH:i'),
                'end_at' => now()->addDays(4)->setTime(8, 0)->format('Y-m-d\TH:i'),
                'payment_mode' => 'online',
                'promo_code' => 'SEWA50',
            ])
            ->assertSessionHasNoErrors();

        $rental = Rental::query()->latest('id')->firstOrFail();
        $this->assertSame('50000.00', $rental->discount_amount);
        $this->assertSame('business', $rental->discount_bearer);
        // 1 hari: 300.000 + supir 100.000 + fee (layanan 10.000 + platform 9.000 + PPN 2.090)
        // = 421.090, dikurangi promo 50.000 lalu ditambah deposit 200.000.
        $this->assertSame('571090.00', $rental->total_price);

        Http::fake(['https://snap.sandbox.test/*' => Http::response([
            'token' => 'snap-token-rental-promo',
            'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/snap-token-rental-promo',
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
            'transaction_id' => 'promo-rental-'.$payment->getKey(),
            'fraud_status' => 'accept',
        ];
        $payload['signature_key'] = hash(
            'sha512',
            $payload['order_id'].$payload['status_code'].$payload['gross_amount'].'sandbox-server-key-for-promo-tests',
        );
        $this->postJson('/api/v1/webhooks/midtrans', $payload)->assertOk();

        $this->assertSame('paid', $rental->fresh()->status);
        $this->assertSame('350000.00', $this->account('BUSINESS.PAYABLE:'.$agency->getKey()));
        $this->assertSame('200000.00', $this->account('PLATFORM.DEPOSIT_PAYABLE'));
        $this->assertSame(
            0,
            LedgerTransaction::query()->where('category', 'PROMO_PLATFORM_BORNE')->count(),
        );
    }

    public function test_agency_promo_page_and_settlement_show_the_discount_detail(): void
    {
        [$agency, $schedule, $origin, $destination] = $this->publishedSchedule();
        $customer = User::factory()->create(['status' => 'active']);
        $owner = User::query()->findOrFail($agency->owner_user_id);

        $this->promo([
            'code' => 'AGENCY20',
            'owner_type' => Promo::OWNER_BUSINESS,
            'business_id' => $agency->getKey(),
            'cost_bearer' => 'business',
            'discount_type' => 'amount',
            'discount_value' => '20000.00',
        ]);

        $booking = $this->book($customer, $schedule, $origin, $destination, 'AGENCY20');
        $this->settle($customer, $booking);

        // PR6: rincian diskon terlihat di halaman laporan penjual.
        $this->actingAs($owner)
            ->get('/mad-trans/agency/'.$agency->getKey().'/laporan')
            ->assertOk()
            ->assertSee('Diskon & promo')
            ->assertSee('AGENCY20')
            ->assertSee('ditanggung business');

        $this->actingAs($owner)
            ->get('/mad-trans/agency/'.$agency->getKey().'/promos')
            ->assertOk()
            ->assertSee('Promo agency')
            ->assertSee('AGENCY20');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function promo(array $overrides): Promo
    {
        return Promo::query()->create(array_merge([
            'name' => 'Promo Uji',
            'applies_to' => 'all',
            'min_spend' => '0.00',
            'max_discount' => null,
            'usage_limit' => null,
            'per_user_limit' => 1,
            'status' => 'active',
        ], $overrides));
    }

    private function book(User $customer, TravelSchedule $schedule, RouteStop $origin, RouteStop $destination, string $promoCode): TravelBooking
    {
        $this->actingAs($customer)
            ->post('/mad-trans/schedules/'.$schedule->getKey().'/bookings', [
                'booking_token' => (string) Str::uuid(),
                'origin_stop_id' => $origin->getKey(),
                'destination_stop_id' => $destination->getKey(),
                'payment_method' => 'midtrans',
                'promo_code' => $promoCode,
                'seats' => [['seat_number' => 'A1', 'name' => 'Pemesan Promo']],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        return TravelBooking::query()->latest('id')->firstOrFail();
    }

    private function settle(User $customer, TravelBooking $booking): void
    {
        Http::fake(['https://snap.sandbox.test/*' => Http::response([
            'token' => 'snap-token-promo',
            'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/snap-token-promo',
        ], 201)]);

        Auth::forgetGuards();
        $this->withToken($customer->createToken('promo-test')->plainTextToken)
            ->withHeader('Idempotency-Key', 'pay-'.$booking->getKey())
            ->postJson('/api/v1/mad-trans/bookings/'.$booking->getKey().'/pay')
            ->assertSuccessful();

        $payment = PaymentIntent::query()->where('booking_id', $booking->getKey())->latest('id')->firstOrFail();
        $payload = [
            'order_id' => $payment->provider_order_id,
            'status_code' => '200',
            'gross_amount' => (string) $payment->amount,
            'transaction_status' => 'settlement',
            'transaction_id' => 'promo-midtrans-'.$payment->getKey(),
            'fraud_status' => 'accept',
        ];
        $payload['signature_key'] = hash(
            'sha512',
            $payload['order_id'].$payload['status_code'].$payload['gross_amount'].'sandbox-server-key-for-promo-tests',
        );
        $this->postJson('/api/v1/webhooks/midtrans', $payload)->assertOk();
    }

    private function account(string $code): string
    {
        return (string) LedgerAccount::query()->where('code', $code)->firstOrFail()->balance_cached;
    }

    /**
     * @return array{0: Business, 1: TravelSchedule, 2: RouteStop, 3: RouteStop}
     */
    private function publishedSchedule(): array
    {
        $owner = User::factory()->accountType(AccountType::AgencyOwner)->create(['status' => 'active']);
        $agency = Business::query()->create([
            'type' => 'agency',
            'name' => 'Travel Promo',
            'code' => 'GM-TRAVEL-PRM1',
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
            'name' => 'Hiace Promo',
            'plate_number' => 'M 9999 PRM',
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

        return [$agency, $schedule, $origin, $destination];
    }
}
