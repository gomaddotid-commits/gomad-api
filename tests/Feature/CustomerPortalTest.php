<?php

namespace Tests\Feature;

use App\Core\Access\Infrastructure\AccessControlSeeder;
use App\Core\Ledger\Infrastructure\LedgerAccountSeeder;
use App\Core\MadTrans\Application\CreateTravelBooking;
use App\Models\Business;
use App\Models\Rental;
use App\Models\RouteStop;
use App\Models\TravelBooking;
use App\Models\TravelRoute;
use App\Models\TravelSchedule;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Permukaan pelanggan: e-ticket, pembatalan dengan akibat yang jelas, profil,
 * keamanan kata sandi, notifikasi, dan riwayat sewa.
 *
 * Yang dijaga di sini bukan tampilan, melainkan janji: kepemilikan data (orang lain
 * tidak bisa melihat/membatalkan), dan pembatalan yang tidak boleh mengubah uang
 * tanpa jejak.
 */
class CustomerPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.mad_trans.service_fee' => 5000,
            'services.mad_trans.platform_fee_basis_points' => 300,
            'services.mad_trans.ppn_basis_points' => 1100,
        ]);
        $this->seed(AccessControlSeeder::class);
        app(LedgerAccountSeeder::class)->run();
    }

    public function test_customer_can_open_cancellation_page_before_paying(): void
    {
        [$customer, $booking] = $this->pendingOnlineBooking();

        $this->actingAs($customer)
            ->get('/mad-trans/bookings/'.$booking->getKey().'/batalkan')
            ->assertOk()
            ->assertSee('Ya, batalkan pesanan')
            ->assertSee('Belum ada dana yang ditarik');
    }

    public function test_cancelling_releases_seats_and_marks_booking_cancelled(): void
    {
        [$customer, $booking] = $this->pendingOnlineBooking();

        $this->actingAs($customer)
            ->post('/mad-trans/bookings/'.$booking->getKey().'/batalkan')
            ->assertRedirect(route('mad-trans.bookings.show', $booking));

        $booking->refresh();
        $this->assertSame('cancelled', $booking->status);
        $this->assertNotNull($booking->cancelled_at);
        $this->assertSame(
            0,
            $booking->passengers()->whereNotNull('seat_number')->count(),
            'Kursi harus dilepas agar bisa dipesan orang lain.',
        );
    }

    public function test_paid_booking_cannot_be_cancelled_by_customer(): void
    {
        [$customer, $booking] = $this->pendingOnlineBooking();
        $booking->forceFill(['status' => 'paid', 'paid_at' => now()])->save();

        $this->actingAs($customer)
            ->get('/mad-trans/bookings/'.$booking->getKey().'/batalkan')
            ->assertOk()
            ->assertSee('pembatalan harus lewat tim operasional');

        $this->actingAs($customer)
            ->post('/mad-trans/bookings/'.$booking->getKey().'/batalkan')
            ->assertRedirect(route('mad-trans.bookings.show', $booking));

        $this->assertSame('paid', $booking->fresh()->status);
    }

    public function test_customer_cannot_cancel_someone_elses_booking(): void
    {
        [, $booking] = $this->pendingOnlineBooking();
        $stranger = User::factory()->create(['status' => 'active']);

        $this->actingAs($stranger)
            ->get('/mad-trans/bookings/'.$booking->getKey().'/batalkan')
            ->assertNotFound();

        $this->actingAs($stranger)
            ->post('/mad-trans/bookings/'.$booking->getKey().'/batalkan')
            ->assertNotFound();

        $this->assertSame('pending', $booking->fresh()->status);
    }

    public function test_e_ticket_shows_cancel_action_only_when_allowed(): void
    {
        [$customer, $booking] = $this->pendingOnlineBooking();

        $this->actingAs($customer)
            ->get('/mad-trans/bookings/'.$booking->getKey())
            ->assertOk()
            ->assertSee('Batalkan pesanan')
            ->assertSee($booking->code);
    }

    public function test_profile_update_changes_name_and_email(): void
    {
        $user = User::factory()->create([
            'status' => 'active',
            'name' => 'Nama Lama',
            'email' => 'lama@gomad.test',
        ]);

        $this->actingAs($user)
            ->patch('/profil', ['name' => 'Nama Baru', 'email' => 'baru@gomad.test'])
            ->assertRedirect();

        $user->refresh();
        $this->assertSame('Nama Baru', $user->name);
        $this->assertSame('baru@gomad.test', $user->email);
    }

    public function test_password_change_requires_the_current_password(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        $this->actingAs($user)
            ->patch('/profil/kata-sandi', [
                'current_password' => 'salah-sekali',
                'password' => 'kata-sandi-baru-panjang',
                'password_confirmation' => 'kata-sandi-baru-panjang',
            ])
            ->assertSessionHasErrors('current_password');
    }

    public function test_password_change_updates_the_credential(): void
    {
        $user = User::factory()->create(['status' => 'active', 'password' => bcrypt('PasswordLama123')]);

        $this->actingAs($user)
            ->patch('/profil/kata-sandi', [
                'current_password' => 'PasswordLama123',
                'password' => 'KataSandiBaru456',
                'password_confirmation' => 'KataSandiBaru456',
            ])
            ->assertRedirect();

        $this->assertTrue(
            Hash::check('KataSandiBaru456', (string) $user->fresh()->password),
        );
    }

    public function test_notifications_page_only_shows_own_messages_and_marks_them_read(): void
    {
        $customer = User::factory()->create(['status' => 'active']);
        $other = User::factory()->create(['status' => 'active']);

        $notification = $customer->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\PaymentReceived',
            'data' => ['title' => 'Pembayaran diterima', 'message' => 'Tiket Anda aktif.'],
        ]);

        $other->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\PaymentReceived',
            'data' => ['title' => 'Milik orang lain'],
        ]);

        $this->actingAs($customer)
            ->get('/notifikasi')
            ->assertOk()
            ->assertSee('Pembayaran diterima')
            ->assertDontSee('Milik orang lain');

        $this->actingAs($customer)
            ->post('/notifikasi/'.$notification->id.'/baca')
            ->assertRedirect();

        $this->assertNotNull(DatabaseNotification::query()->whereKey($notification->id)->firstOrFail()->read_at);
    }

    public function test_read_all_marks_every_unread_notification(): void
    {
        $customer = User::factory()->create(['status' => 'active']);

        foreach (range(1, 2) as $index) {
            $customer->notifications()->create([
                'id' => (string) Str::uuid(),
                'type' => 'App\\Notifications\\PaymentReceived',
                'data' => ['title' => 'Notifikasi '.$index],
            ]);
        }

        $this->actingAs($customer)->post('/notifikasi/baca-semua')->assertRedirect();

        $this->assertSame(0, $customer->unreadNotifications()->count());
    }

    public function test_rental_history_is_scoped_to_the_customer(): void
    {
        $customer = User::factory()->create(['status' => 'active']);
        $other = User::factory()->create(['status' => 'active']);

        [$business, $vehicle] = $this->agencyWithVehicle();

        Rental::query()->create([
            'code' => 'GM-RNT-OWN',
            'business_id' => $business->getKey(),
            'vehicle_id' => $vehicle->getKey(),
            'customer_id' => $customer->getKey(),
            'rental_type' => 'with_driver',
            'start_at' => now()->addDay(),
            'end_at' => now()->addDays(2),
            'days' => 1,
            'price' => '300000.00',
            'driver_fee' => '0.00',
            'service_fee' => '10000.00',
            'platform_fee' => '9000.00',
            'ppn_amount' => '2000.00',
            'total_price' => '321000.00',
            'status' => 'completed',
        ]);

        Rental::query()->create([
            'code' => 'GM-RNT-OTHER',
            'business_id' => $business->getKey(),
            'vehicle_id' => $vehicle->getKey(),
            'customer_id' => $other->getKey(),
            'rental_type' => 'with_driver',
            'start_at' => now()->addDay(),
            'end_at' => now()->addDays(2),
            'days' => 1,
            'price' => '300000.00',
            'driver_fee' => '0.00',
            'service_fee' => '10000.00',
            'platform_fee' => '9000.00',
            'ppn_amount' => '2000.00',
            'total_price' => '321000.00',
            'status' => 'completed',
        ]);

        $this->actingAs($customer)
            ->get('/profil/sewa')
            ->assertOk()
            ->assertSee('GM-RNT-OWN')
            ->assertDontSee('GM-RNT-OTHER');
    }

    /**
     * @return array{0: User, 1: TravelBooking}
     */
    private function pendingOnlineBooking(): array
    {
        [$agency, $vehicle] = $this->agencyWithVehicle();

        $route = TravelRoute::query()->create([
            'business_id' => $agency->getKey(),
            'origin_city' => 'Jakarta',
            'destination_city' => 'Bandung',
            'status' => 'active',
        ]);

        $origin = RouteStop::query()->create([
            'route_id' => $route->getKey(),
            'city_name' => 'Jakarta',
            'name' => 'Terminal Kampung Rambutan',
            'stop_order' => 1,
        ]);

        $destination = RouteStop::query()->create([
            'route_id' => $route->getKey(),
            'city_name' => 'Bandung',
            'name' => 'Terminal Cicaheum',
            'stop_order' => 2,
        ]);

        $route->pricing()->create([
            'origin_stop_id' => $origin->getKey(),
            'destination_stop_id' => $destination->getKey(),
            'price' => '150000.00',
            'is_active' => true,
        ]);

        $owner = User::query()->findOrFail($agency->owner_user_id);

        $schedule = TravelSchedule::query()->create([
            'business_id' => $agency->getKey(),
            'route_id' => $route->getKey(),
            'vehicle_id' => $vehicle->getKey(),
            'departure_date' => now()->addDays(2)->toDateString(),
            'departure_time' => '08:00',
            'travel_class' => 'regular',
            'price_per_seat' => '150000.00',
            'status' => 'published',
            'proposed_by' => $owner->getKey(),
            'allow_cod' => false,
            'payment_methods' => ['midtrans'],
        ]);

        foreach ([$origin, $destination] as $stop) {
            $schedule->stops()->create([
                'route_stop_id' => $stop->getKey(),
                'stop_order' => $stop->stop_order,
            ]);
        }

        $customer = User::factory()->create(['status' => 'active']);

        $booking = app(CreateTravelBooking::class)->execute(
            $customer,
            $schedule->fresh(['stops.routeStop']),
            $origin->getKey(),
            $destination->getKey(),
            [['seat_number' => 'A1', 'name' => 'Penumpang Uji', 'phone' => '+628111222333']],
            'midtrans',
            'cancel-test-'.uniqid(),
        );

        return [$customer, $booking];
    }

    /**
     * @return array{0: Business, 1: Vehicle}
     */
    private function agencyWithVehicle(): array
    {
        $owner = User::factory()->create(['status' => 'active']);

        $agency = Business::query()->create([
            'type' => 'agency',
            'name' => 'Agency Portal',
            'code' => 'GM-AGENCY-CUS1',
            'owner_user_id' => $owner->getKey(),
            'status' => 'active',
        ]);

        $vehicle = Vehicle::query()->create([
            'business_id' => $agency->getKey(),
            'name' => 'Hiace Portal',
            'plate_number' => 'B 2222 CUS',
            'seat_layout' => ['A1', 'A2', 'B1', 'B2'],
            'capacity' => 4,
            'status' => 'active',
        ]);

        return [$agency, $vehicle];
    }
}
