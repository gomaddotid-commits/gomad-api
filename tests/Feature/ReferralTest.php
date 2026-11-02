<?php

namespace Tests\Feature;

use App\Core\Access\Infrastructure\AccessControlSeeder;
use App\Core\Ledger\Infrastructure\LedgerAccountSeeder;
use App\Core\Referral\Application\ReferralCode;
use App\Models\Business;
use App\Models\LedgerAccount;
use App\Models\PaymentIntent;
use App\Models\Promo;
use App\Models\Referral;
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

class ReferralTest extends TestCase
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
            'services.midtrans.server_key' => 'sandbox-server-key-for-referral-tests',
            'services.midtrans.client_key' => 'sandbox-client-key-for-referral-tests',
            'services.midtrans.snap_url' => 'https://snap.sandbox.test/v1/transactions',
            'services.referral.referee_discount_percent' => 10,
            'services.referral.referee_discount_cap' => 25000,
            'services.referral.referrer_reward' => 25000,
        ]);
        $this->seed(AccessControlSeeder::class);
        app(LedgerAccountSeeder::class)->run();
    }

    public function test_registering_with_a_referral_code_issues_a_platform_borne_welcome_promo(): void
    {
        $referrer = User::factory()->create(['status' => 'active']);
        $code = app(ReferralCode::class)->for($referrer);

        $this->post('/register', [
            'name' => 'Pemakai Baru',
            'phone' => '081234567890',
            'email' => 'baru@gomad.test',
            'password' => 'GomadDemo2026!',
            'password_confirmation' => 'GomadDemo2026!',
            'ref' => $code,
        ])->assertRedirect('/dashboard');

        $referee = User::query()->where('email', 'baru@gomad.test')->firstOrFail();
        $referral = Referral::query()->where('referee_user_id', $referee->getKey())->firstOrFail();

        $this->assertSame(Referral::STATUS_PENDING, $referral->status);
        $this->assertSame($referrer->getKey(), (int) $referral->referrer_user_id);

        $welcome = $referral->refereePromo;
        $this->assertNotNull($welcome);
        $this->assertSame('platform', $welcome->cost_bearer);
        $this->assertSame(Promo::OWNER_PLATFORM, $welcome->owner_type);

        // Pengguna baru juga mendapat kode sendiri untuk dibagikan.
        $this->assertNotEmpty($referee->fresh()->referral_code);
    }

    public function test_unknown_referral_code_is_rejected_before_the_account_is_created(): void
    {
        $this->post('/register', [
            'name' => 'Pemakai Salah',
            'phone' => '081234567891',
            'email' => 'salah@gomad.test',
            'password' => 'GomadDemo2026!',
            'password_confirmation' => 'GomadDemo2026!',
            'ref' => 'GMNOPE12',
        ])->assertSessionHasErrors('ref');

        $this->assertNull(User::query()->where('email', 'salah@gomad.test')->first());
    }

    public function test_first_paid_transaction_rewards_the_referrer_with_a_platform_borne_promo(): void
    {
        [$agency, $schedule, $origin, $destination] = $this->publishedSchedule();
        $referrer = User::factory()->create(['status' => 'active']);
        $code = app(ReferralCode::class)->for($referrer);

        $this->post('/register', [
            'name' => 'Pemakai Baru',
            'phone' => '081234567892',
            'email' => 'baru2@gomad.test',
            'password' => 'GomadDemo2026!',
            'password_confirmation' => 'GomadDemo2026!',
            'ref' => $code,
        ])->assertRedirect('/dashboard');

        $referee = User::query()->where('email', 'baru2@gomad.test')->firstOrFail();
        $welcome = Referral::query()->where('referee_user_id', $referee->getKey())->firstOrFail()->refereePromo;

        // Pengundang belum dapat hadiah sebelum transaksi berbayar pertama.
        Auth::forgetGuards();
        $this->actingAs($referee)
            ->post('/mad-trans/schedules/'.$schedule->getKey().'/bookings', [
                'booking_token' => (string) Str::uuid(),
                'origin_stop_id' => $origin->getKey(),
                'destination_stop_id' => $destination->getKey(),
                'payment_method' => 'midtrans',
                'promo_code' => $welcome->code,
                'seats' => [['seat_number' => 'A1', 'name' => 'Pemakai Baru']],
            ])
            ->assertSessionHasNoErrors();

        $booking = TravelBooking::query()->latest('id')->firstOrFail();
        $referral = Referral::query()->where('referee_user_id', $referee->getKey())->firstOrFail();
        $this->assertSame(Referral::STATUS_PENDING, $referral->status);

        Http::fake(['https://snap.sandbox.test/*' => Http::response([
            'token' => 'snap-token-referral',
            'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/snap-token-referral',
        ], 201)]);

        Auth::forgetGuards();
        $this->withToken($referee->createToken('referral-test')->plainTextToken)
            ->withHeader('Idempotency-Key', 'pay-referral')
            ->postJson('/api/v1/mad-trans/bookings/'.$booking->getKey().'/pay')
            ->assertSuccessful();

        $payment = PaymentIntent::query()->where('booking_id', $booking->getKey())->latest('id')->firstOrFail();
        $payload = [
            'order_id' => $payment->provider_order_id,
            'status_code' => '200',
            'gross_amount' => (string) $payment->amount,
            'transaction_status' => 'settlement',
            'transaction_id' => 'referral-midtrans-'.$payment->getKey(),
            'fraud_status' => 'accept',
        ];
        $payload['signature_key'] = hash(
            'sha512',
            $payload['order_id'].$payload['status_code'].$payload['gross_amount'].'sandbox-server-key-for-referral-tests',
        );
        $this->postJson('/api/v1/webhooks/midtrans', $payload)->assertOk();

        $referral = $referral->fresh();
        $this->assertSame(Referral::STATUS_REWARDED, $referral->status);
        $this->assertNotNull($referral->qualified_at);
        $this->assertNotNull($referral->rewarded_at);

        $reward = $referral->referrerPromo;
        $this->assertNotNull($reward);
        $this->assertSame('platform', $reward->cost_bearer);
        $this->assertSame('25000.00', $reward->discount_value);

        // PR4: hadiah referral tidak memotong bagian agency.
        $this->assertSame(
            '150000.00',
            (string) LedgerAccount::query()->where('code', 'BUSINESS.PAYABLE:'.$agency->getKey())->firstOrFail()->balance_cached,
        );
    }

    public function test_referral_page_shows_the_code_and_invites(): void
    {
        $referrer = User::factory()->create(['status' => 'active']);
        $code = app(ReferralCode::class)->for($referrer);

        $this->actingAs($referrer)
            ->get('/referral')
            ->assertOk()
            ->assertSee($code)
            ->assertSee('Referral');
    }

    /**
     * @return array{0: Business, 1: TravelSchedule, 2: RouteStop, 3: RouteStop}
     */
    private function publishedSchedule(): array
    {
        $owner = User::factory()->create(['status' => 'active']);
        $agency = Business::query()->create([
            'type' => 'agency',
            'name' => 'Travel Referral',
            'code' => 'GM-TRAVEL-RFL1',
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
            'name' => 'Hiace Referral',
            'plate_number' => 'M 7777 RFL',
            'seat_layout' => ['A1', 'A2'],
            'capacity' => 2,
            'status' => 'active',
        ]);
        $schedule = TravelSchedule::query()->create([
            'business_id' => $agency->getKey(),
            'vehicle_id' => $vehicle->getKey(),
            'route_id' => $route->getKey(),
            'departure_date' => now()->addDay()->toDateString(),
            'departure_time' => '09:00:00',
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
