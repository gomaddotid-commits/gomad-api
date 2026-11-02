<?php

namespace Tests\Feature;

use App\Core\Access\Infrastructure\AccessControlSeeder;
use App\Core\Identity\Domain\AccountType;
use App\Core\Ledger\Infrastructure\LedgerAccountSeeder;
use App\Models\Business;
use App\Models\PlatformUserRole;
use App\Models\RoutePricing;
use App\Models\RouteStop;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\TravelBooking;
use App\Models\TravelRoute;
use App\Models\TravelSchedule;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * D6 — lapisan bersama: tiket dukungan dan pelacakan perjalanan.
 *
 * Yang dikunci di sini adalah aturan yang mudah rusak tanpa terasa:
 * pengguna tidak boleh melihat tiket orang lain, catatan internal tidak boleh
 * bocor ke pengguna, dan pelacakan tidak boleh menampilkan data palsu.
 */
class SupportAndTrackingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        app(LedgerAccountSeeder::class)->run();
    }

    public function test_customer_opens_ticket_replies_and_closes_it(): void
    {
        $customer = User::factory()->create(['status' => 'active']);

        $this->actingAs($customer)
            ->get('/dukungan')
            ->assertOk()
            ->assertSee('Tiket dukungan');

        $this->actingAs($customer)
            ->post('/dukungan', [
                'category' => 'pembayaran',
                'subject' => 'Dana terpotong tetapi tiket belum terbit',
                'body' => 'Saya sudah bayar lewat QRIS sepuluh menit lalu, status masih menunggu.',
                'priority' => 'high',
            ])
            ->assertRedirect();

        $ticket = SupportTicket::query()->firstOrFail();
        $this->assertSame('open', $ticket->status);
        $this->assertSame(1, SupportMessage::query()->count());

        $this->actingAs($customer)
            ->post('/dukungan/'.$ticket->getKey().'/balas', ['body' => 'Tambahan: kode pesanan saya MT-TR-1.'])
            ->assertRedirect();
        $this->assertSame(2, SupportMessage::query()->count());

        $this->actingAs($customer)
            ->post('/dukungan/'.$ticket->getKey().'/tutup')
            ->assertRedirect();

        $this->assertSame('closed', $ticket->fresh()->status);
        $this->assertSame($customer->getKey(), $ticket->fresh()->closed_by);

        // Tiket tertutup tidak bisa dibalas pengguna; harus buat tiket baru.
        $this->actingAs($customer)
            ->post('/dukungan/'.$ticket->getKey().'/balas', ['body' => 'Halo lagi'])
            ->assertSessionHasErrors('support');
    }

    public function test_other_user_cannot_read_someone_elses_ticket(): void
    {
        $owner = User::factory()->create(['status' => 'active']);
        $outsider = User::factory()->create(['status' => 'active']);

        $ticket = SupportTicket::query()->create([
            'code' => 'SP-TEST-0001',
            'user_id' => $owner->getKey(),
            'category' => 'akun',
            'subject' => 'Tidak bisa masuk',
            'status' => 'open',
            'priority' => 'normal',
            'last_message_at' => now(),
        ]);

        $this->actingAs($outsider)->get('/dukungan/'.$ticket->getKey())->assertForbidden();
        $this->actingAs($outsider)
            ->post('/dukungan/'.$ticket->getKey().'/balas', ['body' => 'menyusup'])
            ->assertForbidden();
    }

    public function test_staff_sees_queue_and_internal_notes_stay_hidden_from_customer(): void
    {
        $customer = User::factory()->accountType(AccountType::Customer)->create(['status' => 'active']);
        $admin = User::factory()->accountType(AccountType::Admin)->create(['status' => 'active']);
        PlatformUserRole::query()->create(['user_id' => $admin->getKey(), 'role' => 'admin']);

        $this->actingAs($customer)->post('/dukungan', [
            'category' => 'perjalanan',
            'subject' => 'Supir belum datang',
            'body' => 'Jadwal 07:30 belum ada kendaraan di titik jemput.',
            'priority' => 'high',
        ]);
        $ticket = SupportTicket::query()->firstOrFail();

        $this->actingAs($admin)
            ->get('/admin/dukungan')
            ->assertOk()
            ->assertSee('Supir belum datang');

        $this->actingAs($admin)
            ->post('/admin/dukungan/'.$ticket->getKey().'/balas', [
                'body' => 'Cek internal: kendaraan cadangan disiapkan.',
                'internal' => 1,
            ])
            ->assertRedirect();

        $this->assertSame('open', $ticket->fresh()->status);

        $this->actingAs($customer)
            ->get('/dukungan/'.$ticket->getKey())
            ->assertOk()
            ->assertDontSee('Cek internal');

        $this->actingAs($admin)
            ->get('/admin/dukungan/'.$ticket->getKey())
            ->assertOk()
            ->assertSee('Cek internal');

        $this->actingAs($admin)
            ->post('/admin/dukungan/'.$ticket->getKey().'/balas', ['body' => 'Kendaraan cadangan sedang bergerak.'])
            ->assertRedirect();

        $this->assertSame('answered', $ticket->fresh()->status);

        $this->actingAs($customer)
            ->get('/dukungan/'.$ticket->getKey())
            ->assertOk()
            ->assertSee('Kendaraan cadangan sedang bergerak');
    }

    public function test_support_api_matches_the_web_rules(): void
    {
        $customer = User::factory()->create(['status' => 'active']);
        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/v1/support/tickets', [
            'category' => 'akun',
            'subject' => 'Tidak menerima kode verifikasi',
            'body' => 'Sudah menunggu sepuluh menit, email belum masuk.',
            'priority' => 'normal',
        ])->assertCreated();

        $ticketId = $response->json('data.id');

        $this->getJson('/api/v1/support/tickets')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->postJson('/api/v1/support/tickets/'.$ticketId.'/messages', ['body' => 'Masih belum masuk.'])
            ->assertCreated()
            ->assertJsonPath('data.ticket_status', 'open');

        $outsider = User::factory()->create(['status' => 'active']);
        Sanctum::actingAs($outsider);
        $this->getJson('/api/v1/support/tickets/'.$ticketId)->assertForbidden();
        $this->getJson('/api/v1/admin/support/tickets')->assertForbidden();
    }

    public function test_booking_tracking_shows_recorded_facts_only(): void
    {
        [$customer, $booking, $schedule] = $this->bookingWithSchedule();

        $this->actingAs($customer)
            ->get('/mad-trans/bookings/'.$booking->getKey().'/perjalanan')
            ->assertOk()
            ->assertSee('Jakarta → Bandung')
            ->assertSee('Posisi kendaraan belum dikirim ke sistem');

        $this->actingAs($customer)
            ->get('/mad-trans/bookings/'.$booking->getKey().'/perjalanan')
            ->assertSee($booking->code);

        $other = User::factory()->create(['status' => 'active']);
        $this->actingAs($other)
            ->get('/mad-trans/bookings/'.$booking->getKey().'/perjalanan')
            ->assertNotFound();

        Sanctum::actingAs($customer);
        $this->getJson('/api/v1/mad-trans/bookings/'.$booking->getKey().'/tracking')
            ->assertOk()
            ->assertJsonPath('data.schedule_status', 'published')
            ->assertJsonPath('data.boarded_count', 0)
            ->assertJsonPath('data.passenger_count', 1)
            ->assertJsonPath('data.timeline.0.done', true);

        $this->assertNotNull($schedule);
    }

    public function test_agency_tracking_board_lists_departures_for_members_only(): void
    {
        [$customer, $booking, $schedule] = $this->bookingWithSchedule();
        $agency = $schedule->business;
        $owner = User::query()->findOrFail($agency->owner_user_id);

        $this->actingAs($owner)
            ->get('/mad-trans/agency/'.$agency->getKey().'/pelacakan')
            ->assertOk()
            ->assertSee('Papan pelacakan')
            ->assertSee('0/1 naik');

        $this->actingAs($customer)
            ->get('/mad-trans/agency/'.$agency->getKey().'/pelacakan')
            ->assertForbidden();
    }

    private function bookingWithSchedule(): array
    {
        $owner = User::factory()->accountType(AccountType::AgencyOwner)->create(['status' => 'active']);
        $customer = User::factory()->accountType(AccountType::Customer)->create(['status' => 'active']);

        $agency = Business::query()->create([
            'type' => 'agency',
            'name' => 'Agency Lacak',
            'code' => 'GM-LACAK-1',
            'owner_user_id' => $owner->getKey(),
            'status' => 'active',
        ]);
        $agency->members()->attach($owner, ['role' => 'pemilik', 'status' => 'active', 'joined_at' => now()]);

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
            'name' => 'Terminal Kampung Rambutan',
        ]);
        $destination = RouteStop::query()->create([
            'route_id' => $route->getKey(),
            'stop_order' => 2,
            'city_name' => 'Bandung',
            'name' => 'Terminal Cicaheum',
        ]);
        $pricing = RoutePricing::query()->create([
            'route_id' => $route->getKey(),
            'origin_stop_id' => $origin->getKey(),
            'destination_stop_id' => $destination->getKey(),
            'price' => '120000.00',
            'is_active' => true,
        ]);
        $vehicle = Vehicle::query()->create([
            'business_id' => $agency->getKey(),
            'name' => 'Hiace Lacak',
            'plate_number' => 'B 7777 LK',
            'seat_layout' => ['A1', 'A2'],
            'capacity' => 2,
            'status' => 'active',
        ]);
        $schedule = TravelSchedule::query()->create([
            'business_id' => $agency->getKey(),
            'vehicle_id' => $vehicle->getKey(),
            'route_id' => $route->getKey(),
            'departure_date' => now()->toDateString(),
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

        $booking = TravelBooking::query()->create([
            'code' => 'MT-LACAK-0001',
            'schedule_id' => $schedule->getKey(),
            'customer_id' => $customer->getKey(),
            'origin_stop_id' => $origin->getKey(),
            'destination_stop_id' => $destination->getKey(),
            'route_pricing_id' => $pricing->getKey(),
            'total_passengers' => 1,
            'base_price' => '120000.00',
            'service_fee' => '5000.00',
            'platform_fee' => '3600.00',
            'discount_amount' => '0.00',
            'ppn_amount' => '946.00',
            'total_price' => '129546.00',
            'payment_method' => 'midtrans',
            'status' => 'paid',
            'idempotency_key' => 'lacak-1',
            'request_hash' => str_repeat('a', 64),
            'expires_at' => now()->addMinutes(30),
            'paid_at' => now(),
        ]);

        $booking->passengers()->create([
            'schedule_id' => $schedule->getKey(),
            'name' => 'Penumpang Lacak',
            'seat_snapshot' => 'A1',
            'seat_number' => 'A1',
        ]);

        return [$customer, $booking, $schedule];
    }
}
