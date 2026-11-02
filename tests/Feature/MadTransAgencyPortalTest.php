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
use App\Models\Business;
use App\Models\RoutePricing;
use App\Models\RouteStop;
use App\Models\TravelRoute;
use App\Models\TravelSchedule;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Withdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MadTransAgencyPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.mad_trans.service_fee' => 5000,
            'services.mad_trans.platform_fee_basis_points' => 300,
            'services.mad_trans.ppn_basis_points' => 1100,
            'services.midtrans.server_key' => 'sandbox-server-key-for-portal-tests',
            'services.midtrans.client_key' => 'sandbox-client-key-for-portal-tests',
            'services.midtrans.snap_url' => 'https://snap.sandbox.test/v1/transactions',
        ]);
        $this->seed(AccessControlSeeder::class);
        app(LedgerAccountSeeder::class)->run();
    }

    public function test_owner_can_build_route_vehicle_schedule_and_publish_it(): void
    {
        [$owner, $agency] = $this->agency();

        $this->actingAs($owner)->get('/mad-trans/agency')->assertOk()->assertSee('Travel Portal');

        $this->actingAs($owner)
            ->post('/mad-trans/agency/'.$agency->getKey().'/vehicles', [
                'name' => 'Hiace Portal',
                'plate_number' => 'B 9999 XX',
                'seat_rows' => [2, 2, 0, 0, 0, 0],
                'has_driver_seat' => '1',
            ])
            ->assertRedirect();

        $vehicle = Vehicle::query()->where('plate_number', 'B 9999 XX')->firstOrFail();
        $this->assertSame(['A1', 'A2', 'B1', 'B2'], $vehicle->seat_layout);
        $this->assertTrue($vehicle->has_driver_seat);

        $this->actingAs($owner)
            ->post('/mad-trans/agency/'.$agency->getKey().'/routes', [
                'stops' => [
                    ['city_name' => 'Jakarta', 'name' => 'Terminal Lebak Bulus'],
                    ['city_name' => 'Cirebon', 'name' => 'Terminal Harjamukti'],
                    ['city_name' => 'Bandung', 'name' => 'Terminal Cicaheum'],
                ],
                'leg_prices' => [60000, 60000],
            ])
            ->assertRedirect();

        $route = $agency->travelRoutes()->firstOrFail();
        $this->assertSame(3, RouteStop::query()->where('route_id', $route->getKey())->count());

        $prices = $route->pricing()->get()
            ->mapWithKeys(fn ($price): array => [
                $price->origin_stop_id.'-'.$price->destination_stop_id => (string) $price->price,
            ])
            ->all();
        $stopIds = $route->stops()->orderBy('stop_order')->pluck('id')->values();
        $this->assertSame(3, count($prices));
        $this->assertSame('60000.00', $prices[$stopIds[0].'-'.$stopIds[1]]);
        $this->assertSame('60000.00', $prices[$stopIds[1].'-'.$stopIds[2]]);
        $this->assertSame('120000.00', $prices[$stopIds[0].'-'.$stopIds[2]]);

        $this->actingAs($owner)
            ->post('/mad-trans/agency/'.$agency->getKey().'/schedules', [
                'route_id' => $route->getKey(),
                'vehicle_id' => $vehicle->getKey(),
                'departure_at' => now()->addDay()->setTime(7, 30)->format('Y-m-d\TH:i'),
                'travel_class' => 'regular',
            ])
            ->assertRedirect();

        $schedule = TravelSchedule::query()->firstOrFail();
        $this->assertSame('pending_approval', $schedule->status);
        $this->assertSame('120000.00', $schedule->price_per_seat);

        $this->actingAs($owner)
            ->post('/mad-trans/agency/schedules/'.$schedule->getKey().'/approve')
            ->assertRedirect();

        $this->assertSame('published', $schedule->fresh()->status);

        $customer = User::factory()->create(['status' => 'active']);

        $this->actingAs($customer)
            ->get('/mad-trans?origin_city=Jakarta&destination_city=Bandung&departure_date='.now()->addDay()->toDateString())
            ->assertOk()
            ->assertSee('Hiace Portal');

        $this->actingAs($customer)
            ->get('/mad-trans?origin_city=Cirebon&destination_city=Bandung&departure_date='.now()->addDay()->toDateString())
            ->assertOk()
            ->assertSee('Hiace Portal');
    }

    public function test_outsider_cannot_open_another_agency_portal(): void
    {
        [, $agency] = $this->agency();
        $outsider = User::factory()->create(['status' => 'active']);

        $this->actingAs($outsider)
            ->get('/mad-trans/agency/'.$agency->getKey())
            ->assertForbidden();
    }

    public function test_owner_can_start_collateral_topup_from_agency_portal(): void
    {
        [$owner, $agency] = $this->agency();

        config(['services.midtrans.snap_url' => 'https://snap.sandbox.test/snap/snap.js']);

        Http::fake(['*' => Http::response([
            'token' => 'snap-token-demo',
            'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/snap-token-demo',
        ], 201)]);

        $this->actingAs($owner)
            ->get('/mad-trans/agency/'.$agency->getKey())
            ->assertOk()
            ->assertSee('Jaminan COD');

        $this->actingAs($owner)
            ->post('/mad-trans/agency/'.$agency->getKey().'/collateral', ['amount' => 500000])
            ->assertRedirect('https://app.sandbox.midtrans.com/snap/v4/redirection/snap-token-demo');

        Http::assertSent(fn ($request): bool => $request->url() === 'https://snap.sandbox.test/snap/v1/transactions');

        $this->assertDatabaseHas('payment_intents', [
            'business_id' => $agency->getKey(),
            'purpose' => 'collateral_topup',
            'amount' => '500000.00',
        ]);
    }

    public function test_schedule_cannot_start_before_departure_and_can_be_finished_after(): void
    {
        [$owner, , $schedule] = $this->publishedAgencySchedule();

        $this->actingAs($owner)
            ->post('/mad-trans/agency/schedules/'.$schedule->getKey().'/start')
            ->assertSessionHasErrors('agency');

        $this->assertSame('published', $schedule->fresh()->status);

        Carbon::setTestNow(now()->addDay()->setTime(8, 0));

        $this->actingAs($owner)
            ->post('/mad-trans/agency/schedules/'.$schedule->getKey().'/start')
            ->assertSessionHasNoErrors();

        $this->assertSame('in_progress', $schedule->fresh()->status);

        $this->actingAs($owner)
            ->post('/mad-trans/agency/schedules/'.$schedule->getKey().'/finish')
            ->assertSessionHasNoErrors();

        $this->assertSame('completed', $schedule->fresh()->status);

        Carbon::setTestNow();
    }

    private function publishedAgencySchedule(): array
    {
        [$owner, $agency] = $this->agency();

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
            'name' => 'Hiace Portal',
            'plate_number' => 'B 8888 YY',
            'seat_layout' => ['A1', 'A2'],
            'capacity' => 2,
            'status' => 'active',
        ]);
        $schedule = TravelSchedule::query()->create([
            'business_id' => $agency->getKey(),
            'vehicle_id' => $vehicle->getKey(),
            'route_id' => $route->getKey(),
            'departure_date' => now()->addDay()->toDateString(),
            'departure_time' => '07:30:00',
            'travel_class' => 'regular',
            'price_per_seat' => '120000.00',
            'status' => 'published',
            'proposed_by' => $owner->getKey(),
            'allow_cod' => false,
            'payment_methods' => ['midtrans'],
        ]);
        $schedule->stops()->createMany([
            ['route_stop_id' => $origin->getKey(), 'stop_order' => 1],
            ['route_stop_id' => $destination->getKey(), 'stop_order' => 2],
        ]);

        return [$owner, $agency, $schedule];
    }

    public function test_owner_can_request_a_withdrawal_once_balance_is_settled(): void
    {
        [$owner, $agency] = $this->agency();

        $payload = [
            'bank_code' => 'BCA',
            'bank_account_number' => '1234567890',
            'bank_account_name' => 'Pemilik Travel',
            'amount' => 100000,
        ];

        $this->actingAs($owner)
            ->from('/mad-trans/agency/'.$agency->getKey())
            ->post('/mad-trans/agency/'.$agency->getKey().'/withdrawals', $payload)
            ->assertSessionHasErrors('withdrawal');

        $this->fundAvailable($agency, 500000);

        $this->actingAs($owner)
            ->post('/mad-trans/agency/'.$agency->getKey().'/withdrawals', $payload)
            ->assertSessionHasNoErrors();

        $withdrawal = Withdrawal::query()->firstOrFail();
        $this->assertSame('100000.00', $withdrawal->amount);
        $this->assertSame('pending', $withdrawal->status);

        $this->actingAs($owner)
            ->get('/mad-trans/agency/'.$agency->getKey().'/pencairan')
            ->assertOk()
            ->assertSee($withdrawal->code);
    }

    public function test_owner_can_open_every_agency_workspace_page(): void
    {
        [$owner, $agency] = $this->agency();

        $pages = [
            '' => 'Pesanan hari ini',
            '/armada' => 'Armada',
            '/rute' => 'Rute',
            '/jadwal' => 'Jadwal',
            '/pengemudi' => 'Pengemudi',
            '/pesanan' => 'Pesanan & penumpang',
            '/rental' => 'Sewa kendaraan',
            '/dompet' => 'Dompet & saldo',
            '/pencairan' => 'Pencairan saldo',
            '/laporan' => 'Laporan & bagi hasil',
        ];

        foreach ($pages as $suffix => $marker) {
            $this->actingAs($owner)
                ->get('/mad-trans/agency/'.$agency->getKey().$suffix)
                ->assertOk()
                ->assertSee($marker);
        }
    }

    public function test_other_agency_cannot_open_workspace_pages(): void
    {
        [, $agency] = $this->agency();

        $outsider = User::factory()->accountType(AccountType::AgencyOwner)->create(['status' => 'active']);
        $other = Business::query()->create([
            'type' => 'agency',
            'name' => 'Travel Lain',
            'code' => 'GM-AGENCY-0002',
            'owner_user_id' => $outsider->getKey(),
            'status' => 'active',
        ]);
        $other->members()->attach($outsider, [
            'role' => 'pemilik',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        foreach (['', '/armada', '/rute', '/jadwal', '/pengemudi', '/pesanan', '/rental', '/dompet', '/pencairan', '/laporan'] as $suffix) {
            $this->actingAs($outsider)
                ->get('/mad-trans/agency/'.$agency->getKey().$suffix)
                ->assertForbidden();
        }
    }

    public function test_wallet_page_shows_seeded_balance_and_mutation(): void
    {
        [$owner, $agency] = $this->agency();
        $this->fundAvailable($agency, 750000);

        $this->actingAs($owner)
            ->get('/mad-trans/agency/'.$agency->getKey().'/dompet')
            ->assertOk()
            ->assertSee('Fixture saldo siap cair')
            ->assertSee('750.000');
    }

    private function fundAvailable(Business $agency, int $amount): void
    {
        app(LedgerAccountSeeder::class)->ensureBusinessAccounts($agency);
        app(PostLedgerTransaction::class)->execute(new PostLedgerTransactionData(
            category: LedgerCategory::Adjustment,
            idempotencyKey: 'test:available-fund:'.$agency->getKey(),
            entries: [
                new LedgerEntryInput('PLATFORM.CASH', LedgerDirection::Debit, (string) $amount),
                new LedgerEntryInput(
                    'BUSINESS.AVAILABLE:'.$agency->getKey(),
                    LedgerDirection::Credit,
                    (string) $amount,
                ),
            ],
            referenceType: 'test',
            referenceId: (int) $agency->getKey(),
            description: 'Fixture saldo siap cair',
        ));
    }

    private function agency(): array
    {
        $owner = User::factory()->accountType(AccountType::AgencyOwner)->create(['status' => 'active']);
        $agency = Business::query()->create([
            'type' => 'agency',
            'name' => 'Travel Portal',
            'code' => 'GM-AGENCY-0001',
            'owner_user_id' => $owner->getKey(),
            'status' => 'active',
        ]);
        $agency->members()->attach($owner, [
            'role' => 'pemilik',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return [$owner, $agency];
    }
}
