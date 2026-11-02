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
use App\Models\BusinessMember;
use App\Models\PaymentIntent;
use App\Models\Promo;
use App\Models\Rental;
use App\Models\RentalDocument;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Kontrak API untuk klien mobile (Fase 9). Memastikan fitur yang hanya ada di web
 * (rental, dokumen lepas kunci, promo, referral, level) juga tersedia lewat JSON.
 */
class MobileApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.rental.service_fee' => 10000,
            'services.rental.platform_fee_basis_points' => 300,
            'services.rental.ppn_basis_points' => 1100,
            'services.midtrans.server_key' => 'sandbox-server-key-for-mobile-api',
            'services.midtrans.client_key' => 'sandbox-client-key-for-mobile-api',
            'services.midtrans.snap_url' => 'https://snap.sandbox.test/snap/v1/transactions',
            'services.midtrans.payment_timeout' => 30,
        ]);
        $this->seed(AccessControlSeeder::class);
        app(LedgerAccountSeeder::class)->run();
    }

    public function test_mobile_lists_rentable_vehicles_with_driver_options(): void
    {
        [$agency, , $driver] = $this->agency();
        $customer = User::factory()->create(['status' => 'active']);
        Sanctum::actingAs($customer);

        $response = $this->getJson('/api/v1/mad-trans/businesses/'.$agency->getKey().'/rental-vehicles')
            ->assertOk()
            ->assertJsonPath('data.vehicles.0.daily_price', '300000.00')
            ->assertJsonPath('data.self_drive_ready', false)
            ->assertJsonPath('data.drivers.0.membership_id', $driver->getKey());

        $this->assertSame($agency->name, $response->json('data.business.name'));
    }

    public function test_mobile_creates_a_rental_and_lists_it_for_the_owner_only(): void
    {
        [$agency, $vehicle, $driver] = $this->agency();
        $customer = User::factory()->create(['status' => 'active']);
        $other = User::factory()->create(['status' => 'active']);
        Sanctum::actingAs($customer);

        $this->postJson('/api/v1/mad-trans/businesses/'.$agency->getKey().'/rental-bookings', [
            'rental_type' => 'with_driver',
            'vehicle_id' => $vehicle->getKey(),
            'driver_membership_id' => $driver->getKey(),
            'start_at' => now()->addDay()->setTime(8, 0)->toIso8601String(),
            'end_at' => now()->addDays(3)->setTime(8, 0)->toIso8601String(),
            'idempotency_key' => 'mobile-rental-1',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.days', 2)
            ->assertJsonPath('data.total_price', '831080.00');

        $rental = Rental::query()->latest('id')->firstOrFail();
        $this->assertSame((int) $customer->getKey(), (int) $rental->customer_id);

        $this->getJson('/api/v1/mad-trans/rental-bookings')
            ->assertOk()
            ->assertJsonPath('data.0.code', $rental->code);

        $this->getJson('/api/v1/mad-trans/rental-bookings/'.$rental->getKey())
            ->assertOk()
            ->assertJsonPath('data.code', $rental->code);

        Sanctum::actingAs($other);
        $this->getJson('/api/v1/mad-trans/rental-bookings/'.$rental->getKey())->assertNotFound();
        $this->getJson('/api/v1/mad-trans/rental-bookings')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_mobile_self_drive_is_rejected_with_a_readable_error(): void
    {
        [$agency, $vehicle] = $this->agency();
        $customer = User::factory()->create(['status' => 'active']);
        Sanctum::actingAs($customer);

        $this->postJson('/api/v1/mad-trans/businesses/'.$agency->getKey().'/rental-bookings', [
            'rental_type' => 'self_drive',
            'vehicle_id' => $vehicle->getKey(),
            'start_at' => now()->addDay()->setTime(8, 0)->toIso8601String(),
            'end_at' => now()->addDays(2)->setTime(8, 0)->toIso8601String(),
            'idempotency_key' => 'mobile-rental-self-drive',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'RENTAL_REJECTED');

        $this->assertSame(0, Rental::query()->count());
    }

    public function test_mobile_pays_a_rental_and_receives_a_snap_token(): void
    {
        [$agency, $vehicle, $driver] = $this->agency();
        $customer = User::factory()->create(['status' => 'active']);
        Sanctum::actingAs($customer);

        $this->postJson('/api/v1/mad-trans/businesses/'.$agency->getKey().'/rental-bookings', [
            'rental_type' => 'with_driver',
            'vehicle_id' => $vehicle->getKey(),
            'driver_membership_id' => $driver->getKey(),
            'start_at' => now()->addDay()->setTime(8, 0)->toIso8601String(),
            'end_at' => now()->addDays(3)->setTime(8, 0)->toIso8601String(),
            'idempotency_key' => 'mobile-rental-pay',
        ])->assertCreated();

        $rental = Rental::query()->latest('id')->firstOrFail();

        Http::fake(['*' => Http::response([
            'token' => 'snap-token-mobile',
            'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/snap-token-mobile',
        ], 201)]);

        $this->postJson('/api/v1/mad-trans/rental-bookings/'.$rental->getKey().'/pay', [
            'idempotency_key' => 'mobile-pay-1',
        ])
            ->assertOk()
            ->assertJsonPath('data.snap_token', 'snap-token-mobile')
            ->assertJsonPath('data.provider', 'midtrans_snap')
            ->assertJsonPath('data.redirect_url', 'https://app.sandbox.midtrans.com/snap/v4/redirection/snap-token-mobile');

        // Pemanggilan ulang memakai idempotency key yang sama tidak membuat intent baru.
        $this->postJson('/api/v1/mad-trans/rental-bookings/'.$rental->getKey().'/pay', [
            'idempotency_key' => 'mobile-pay-1',
        ])->assertOk();

        $this->assertSame(1, PaymentIntent::query()->where('rental_id', $rental->getKey())->count());
    }

    public function test_mobile_uploads_and_lists_self_drive_documents(): void
    {
        $customer = User::factory()->create(['status' => 'active']);
        Sanctum::actingAs($customer);

        $this->getJson('/api/v1/rental-documents')
            ->assertOk()
            ->assertJsonPath('data.verified_all', false)
            ->assertJsonCount(3, 'data.documents')
            ->assertJsonPath('data.documents.0.status', 'missing');

        $this->post('/api/v1/rental-documents', [
            'type' => 'ktp',
            'file' => UploadedFile::fake()->create('ktp.jpg', 120, 'image/jpeg'),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'ktp')
            ->assertJsonPath('data.status', 'uploaded');

        $document = RentalDocument::query()->where('user_id', $customer->getKey())->firstOrFail();
        $this->assertSame('ktp', $document->type);

        // Unggah ulang menghapus status verifikasi sebelumnya.
        $document->forceFill(['status' => 'verified', 'verified_at' => now()])->save();

        $this->post('/api/v1/rental-documents', [
            'type' => 'ktp',
            'file' => UploadedFile::fake()->create('ktp-2.jpg', 120, 'image/jpeg'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame('uploaded', $document->fresh()->status);
        $this->assertNull($document->fresh()->verified_at);
        $this->assertSame(1, RentalDocument::query()->where('user_id', $customer->getKey())->count());
    }

    public function test_mobile_validates_promo_codes(): void
    {
        $customer = User::factory()->create(['status' => 'active']);
        Sanctum::actingAs($customer);

        Promo::query()->create([
            'code' => 'MOBILE10',
            'name' => 'Promo Mobile',
            'owner_type' => Promo::OWNER_PLATFORM,
            'cost_bearer' => 'platform',
            'discount_type' => 'percent',
            'discount_value' => '10.00',
            'max_discount' => '50000.00',
            'min_spend' => '0.00',
            'applies_to' => 'all',
            'per_user_limit' => 1,
            'status' => 'active',
        ]);

        $this->postJson('/api/v1/promos/validate', [
            'code' => 'mobile10',
            'applies_to' => 'travel',
            'base_amount' => 120000,
        ])
            ->assertOk()
            ->assertJsonPath('data.code', 'MOBILE10')
            ->assertJsonPath('data.discount_amount', '12000.00')
            ->assertJsonPath('data.cost_bearer', 'platform');

        $this->postJson('/api/v1/promos/validate', [
            'code' => 'TIDAKADA',
            'applies_to' => 'travel',
            'base_amount' => 120000,
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROMO_REJECTED');
    }

    public function test_mobile_reads_referral_and_loyalty_payloads(): void
    {
        $customer = User::factory()->create(['status' => 'active', 'points_cached' => 120]);
        Sanctum::actingAs($customer);

        $code = $this->getJson('/api/v1/referral')
            ->assertOk()
            ->assertJsonCount(0, 'data.invites')
            ->json('data.code');

        $this->assertNotEmpty($code);
        $this->assertSame($code, $customer->fresh()->referral_code);

        $this->getJson('/api/v1/loyalty')
            ->assertOk()
            ->assertJsonPath('data.points', 120)
            ->assertJsonPath('data.level.name', 'Silver')
            ->assertJsonPath('data.next_level.name', 'Gold')
            ->assertJsonPath('data.points_to_next_level', 180);
    }

    public function test_mobile_can_refresh_its_token_without_relogin(): void
    {
        $customer = User::factory()->create(['status' => 'active']);
        $otherDevice = $customer->createToken('Tablet', ['*'], now()->addDays(30));
        $current = $customer->createToken('Pixel 8', ['*'], now()->addDays(30));

        $response = $this->withHeader('Authorization', 'Bearer '.$current->plainTextToken)
            ->postJson('/api/v1/auth/refresh')
            ->assertOk()
            ->assertJsonPath('data.device_name', 'Pixel 8')
            ->assertJsonPath('data.user.id', $customer->getKey());

        $fresh = $response->json('data.token');
        $this->assertIsString($fresh);
        $this->assertNotSame($current->plainTextToken, $fresh);

        // Token lama untuk perangkat ini dirotasi; token perangkat lain tetap hidup.
        $this->assertSame(0, PersonalAccessToken::query()->whereKey($current->accessToken->getKey())->count());
        $this->assertSame(1, PersonalAccessToken::query()->whereKey($otherDevice->accessToken->getKey())->count());

        $this->withHeader('Authorization', 'Bearer '.$fresh)
            ->getJson('/api/v1/me')
            ->assertOk();

        // Guard menyimpan pengguna antar permintaan dalam satu tes, jadi reset dulu
        // supaya benar-benar diuji ulang dari token yang tersimpan.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$current->plainTextToken)
            ->getJson('/api/v1/me')
            ->assertUnauthorized();
    }

    public function test_mobile_refresh_requires_a_valid_token(): void
    {
        $this->postJson('/api/v1/auth/refresh')->assertUnauthorized();
    }

    public function test_mobile_schedule_search_accepts_iso_timestamps(): void
    {
        $customer = User::factory()->create(['status' => 'active']);
        Sanctum::actingAs($customer);

        // Klien Dart mengirim DateTime sebagai ISO 8601, bukan Y-m-d.
        $date = urlencode(now()->addDay()->setTime(0, 0)->toIso8601String());

        $this->getJson('/api/v1/mad-trans/schedules?origin_city=Jakarta&destination_city=Bandung&departure_date='.$date.'&passengers=1')
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    /**
     * @return array{0: Business, 1: Vehicle, 2: BusinessMember}
     */
    public function test_agency_owner_can_read_wallet_bookings_and_withdrawals_over_the_api(): void
    {
        [$agency] = $this->agency();
        $owner = User::query()->findOrFail($agency->owner_user_id);

        app(LedgerAccountSeeder::class)->ensureBusinessAccounts($agency);
        app(PostLedgerTransaction::class)->execute(new PostLedgerTransactionData(
            category: LedgerCategory::Adjustment,
            idempotencyKey: 'mobile:wallet-fund:'.$agency->getKey(),
            entries: [
                new LedgerEntryInput('PLATFORM.CASH', LedgerDirection::Debit, '250000'),
                new LedgerEntryInput('BUSINESS.AVAILABLE:'.$agency->getKey(), LedgerDirection::Credit, '250000'),
            ],
            referenceType: 'test',
            referenceId: (int) $agency->getKey(),
            description: 'Saldo awal uji mobile',
        ));

        Sanctum::actingAs($owner);
        $base = '/api/v1/mad-trans/businesses/'.$agency->getKey();

        $this->getJson($base.'/wallet')
            ->assertOk()
            ->assertJsonPath('data.balances.available', '250000.00')
            ->assertJsonPath('data.mutations.0.direction', 'credit')
            ->assertJsonPath('data.mutations.0.description', 'Saldo awal uji mobile')
            ->assertJsonPath('data.holds_total', '0.00');

        $this->getJson($base.'/bookings')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['current_page', 'last_page', 'total']]);

        $this->getJson($base.'/withdrawals')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->getJson($base.'/rental-bookings')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $outsider = User::factory()->create(['status' => 'active']);
        Sanctum::actingAs($outsider);
        $this->getJson($base.'/wallet')->assertForbidden();
        $this->getJson($base.'/bookings')->assertForbidden();
    }

    private function agency(): array
    {
        $owner = User::factory()->accountType(AccountType::AgencyOwner)->create(['status' => 'active']);
        $driverUser = User::factory()->accountType(AccountType::Driver)->create(['status' => 'active']);

        $agency = Business::query()->create([
            'type' => 'agency',
            'name' => 'Agency Mobile',
            'code' => 'GM-AGENCY-MOB1',
            'owner_user_id' => $owner->getKey(),
            'status' => 'active',
        ]);
        $agency->members()->attach($owner, ['role' => 'pemilik', 'status' => 'active', 'joined_at' => now()]);
        $agency->members()->attach($driverUser, ['role' => 'driver', 'status' => 'active', 'joined_at' => now()]);

        $driver = BusinessMember::query()
            ->where('business_id', $agency->getKey())
            ->where('user_id', $driverUser->getKey())
            ->firstOrFail();

        $vehicle = Vehicle::query()->create([
            'business_id' => $agency->getKey(),
            'name' => 'Avanza Mobile',
            'plate_number' => 'B 9999 MOB',
            'seat_layout' => ['A1', 'A2'],
            'capacity' => 2,
            'rental_daily_price' => '300000.00',
            'rental_driver_daily_fee' => '100000.00',
            'status' => 'active',
        ]);

        return [$agency, $vehicle, $driver];
    }
}
