<?php

namespace Tests\Feature;

use App\Core\Access\Infrastructure\AccessControlSeeder;
use App\Core\Ledger\Infrastructure\LedgerAccountSeeder;
use App\Models\Business;
use App\Models\RouteStop;
use App\Models\TravelRoute;
use App\Models\TravelSchedule;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Permukaan publik: pengunjung tanpa akun harus bisa melihat jadwal dan harga,
 * tetapi tidak boleh melihat apa pun yang bukan milik publik (draf, penumpang,
 * data pribadi) dan tidak boleh memesan tanpa masuk.
 */
class PublicSiteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        app(LedgerAccountSeeder::class)->run();
    }

    public function test_homepage_is_reachable_without_login_and_advertises_search(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('GoMad')
            ->assertSee('Cari', false)
            ->assertSee(route('public.search'), false);
    }

    public function test_guest_can_search_schedules_and_sees_results(): void
    {
        [$agency, $route, $schedule] = $this->publishedSchedule();

        $this->get('/jadwal?origin_city=Jakarta&destination_city=Bandung&departure_date='
                .now()->addDay()->toDateString().'&passengers=1')
            ->assertOk()
            ->assertSee($agency->name)
            ->assertSee('Lihat kursi')
            ->assertSee(route('public.schedule', $schedule), false);
    }

    public function test_guest_sees_an_empty_state_when_no_schedule_matches(): void
    {
        $this->get('/jadwal?origin_city=Sumenep&destination_city=Malang&departure_date='
                .now()->addDay()->toDateString())
            ->assertOk()
            ->assertSee('Tidak ada jadwal pada tanggal itu');
    }

    public function test_search_without_parameters_asks_for_input_instead_of_failing(): void
    {
        $this->get('/jadwal')
            ->assertOk()
            ->assertSee('Isi rute dan tanggal dulu');
    }

    public function test_schedule_detail_is_public_and_points_to_login_for_booking(): void
    {
        [, , $schedule] = $this->publishedSchedule();

        $this->get('/jadwal/'.$schedule->getKey())
            ->assertOk()
            ->assertSee('Masuk untuk memesan')
            ->assertSee(route('login'), false);
    }

    public function test_draft_schedule_is_not_exposed_to_the_public(): void
    {
        [, , $schedule] = $this->publishedSchedule();
        $schedule->forceFill(['status' => 'draft'])->save();

        $this->get('/jadwal/'.$schedule->getKey())->assertNotFound();
    }

    public function test_schedule_of_inactive_agency_is_not_exposed(): void
    {
        [$agency, , $schedule] = $this->publishedSchedule();
        $agency->forceFill(['status' => 'suspended'])->save();

        $this->get('/jadwal/'.$schedule->getKey())->assertNotFound();
    }

    public function test_public_pages_render_for_guest_audience(): void
    {
        $this->get('/mitra')->assertOk()->assertSee('Jadi mitra');
        $this->get('/warung')->assertOk()->assertSee('Mad Warung');
        $this->get('/unduh')->assertOk()->assertSee('Android');
    }

    public function test_public_api_returns_typed_schedule_payload(): void
    {
        [$agency, , $schedule] = $this->publishedSchedule();

        $this->getJson('/api/v1/public/schedules?origin_city=Jakarta&destination_city=Bandung&departure_date='
                .now()->addDay()->toDateString().'&passengers=1')
            ->assertOk()
            ->assertJsonPath('data.0.agency', $agency->name)
            ->assertJsonPath('data.0.origin_city', 'Jakarta')
            ->assertJsonPath('data.0.price_per_seat', '150000.00')
            ->assertJsonPath('data.0.price_per_seat_display', 'Rp 150.000')
            ->assertJsonStructure(['data' => [[
                'id', 'agency', 'origin_city', 'destination_city', 'departure_date',
                'departure_time', 'travel_class', 'vehicle', 'price_per_seat',
                'price_per_seat_display', 'seats_left', 'allow_cod',
            ]]]);

        $this->getJson('/api/v1/public/schedules/'.$schedule->getKey())
            ->assertOk()
            ->assertJsonPath('data.id', $schedule->getKey())
            ->assertJsonStructure(['data' => [
                'id', 'agency', 'vehicle' => ['name', 'capacity', 'seats'], 'stops', 'pricing',
            ]]);
    }

    public function test_public_api_rejects_incomplete_search_with_error_code(): void
    {
        $this->getJson('/api/v1/public/schedules')
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_FAILED');
    }

    public function test_public_api_does_not_expose_internal_booking_data(): void
    {
        [, , $schedule] = $this->publishedSchedule();

        $payload = $this->getJson('/api/v1/public/schedules/'.$schedule->getKey())->json('data');

        $this->assertArrayNotHasKey('reserved_seats', $payload);
        $this->assertArrayNotHasKey('business_id', $payload);
    }

    public function test_partners_api_lists_only_active_businesses(): void
    {
        [$agency] = $this->publishedSchedule();
        Business::query()->create([
            'type' => 'warung',
            'name' => 'Warung Nonaktif',
            'code' => 'GM-WARUNG-OFF',
            'status' => 'draft',
        ]);
        Business::query()->create([
            'type' => 'warung',
            'name' => 'Warung Aktif',
            'code' => 'GM-WARUNG-ON',
            'status' => 'active',
        ]);

        $response = $this->getJson('/api/v1/public/partners')->assertOk();

        $this->assertSame([$agency->name], array_column($response->json('data.agencies'), 'name'));
        $this->assertSame(['Warung Aktif'], array_column($response->json('data.outlets'), 'name'));
    }

    /**
     * @return array{0: Business, 1: TravelRoute, 2: TravelSchedule}
     */
    private function publishedSchedule(): array
    {
        $owner = User::factory()->create(['status' => 'active']);

        $agency = Business::query()->create([
            'type' => 'agency',
            'name' => 'Agency Publik',
            'code' => 'GM-AGENCY-PUB1',
            'owner_user_id' => $owner->getKey(),
            'status' => 'active',
        ]);

        $vehicle = Vehicle::query()->create([
            'business_id' => $agency->getKey(),
            'name' => 'Hiace Publik',
            'plate_number' => 'B 1111 PUB',
            'seat_layout' => ['A1', 'A2', 'B1', 'B2'],
            'capacity' => 4,
            'status' => 'active',
        ]);

        $route = TravelRoute::query()->create([
            'business_id' => $agency->getKey(),
            'origin_city' => 'Jakarta',
            'destination_city' => 'Bandung',
            'status' => 'active',
        ]);

        $originStop = RouteStop::query()->create([
            'route_id' => $route->getKey(),
            'city_name' => 'Jakarta',
            'name' => 'Terminal Kampung Rambutan',
            'stop_order' => 1,
        ]);

        $destinationStop = RouteStop::query()->create([
            'route_id' => $route->getKey(),
            'city_name' => 'Bandung',
            'name' => 'Terminal Cicaheum',
            'stop_order' => 2,
        ]);

        $route->pricing()->create([
            'origin_stop_id' => $originStop->getKey(),
            'destination_stop_id' => $destinationStop->getKey(),
            'price' => '150000.00',
            'is_active' => true,
        ]);

        $schedule = TravelSchedule::query()->create([
            'business_id' => $agency->getKey(),
            'route_id' => $route->getKey(),
            'vehicle_id' => $vehicle->getKey(),
            'departure_date' => now()->addDay()->toDateString(),
            'departure_time' => '07:30',
            'travel_class' => 'ekonomi',
            'price_per_seat' => '150000.00',
            'status' => 'published',
            'proposed_by' => $owner->getKey(),
            'allow_cod' => false,
            'payment_methods' => ['midtrans'],
        ]);

        foreach ([$originStop, $destinationStop] as $stop) {
            $schedule->stops()->create([
                'route_stop_id' => $stop->getKey(),
                'stop_order' => $stop->stop_order,
            ]);
        }

        return [$agency, $route, $schedule];
    }
}
