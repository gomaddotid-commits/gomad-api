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
use App\Core\Rental\Application\CreateRentalBooking;
use App\Core\Rental\Domain\RentalType;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\PlatformUserRole;
use App\Models\RentalDocument;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RentalDocumentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config([
            'services.rental.service_fee' => 10000,
            'services.rental.platform_fee_basis_points' => 300,
            'services.rental.ppn_basis_points' => 1100,
        ]);
        $this->seed(AccessControlSeeder::class);
        app(LedgerAccountSeeder::class)->run();
    }

    public function test_customer_uploads_documents_and_only_admin_can_verify_them(): void
    {
        $customer = User::factory()->create(['status' => 'active']);
        $admin = $this->platformAdmin();
        $other = User::factory()->create(['status' => 'active']);

        $this->actingAs($customer)
            ->post('/mad-trans/rental-documents', [
                'type' => 'ktp',
                'file' => UploadedFile::fake()->create('ktp.jpg', 120, 'image/jpeg'),
            ])
            ->assertRedirect();

        $document = RentalDocument::query()->firstOrFail();
        $this->assertSame('uploaded', $document->status);
        Storage::disk('local')->assertExists($document->file_path);

        $this->actingAs($other)->get('/admin/rental-documents')->assertForbidden();
        $this->actingAs($other)
            ->post('/admin/rental-documents/'.$document->getKey().'/verify')
            ->assertForbidden();

        $this->actingAs($admin)->get('/admin/rental-documents')->assertOk()->assertSee('KTP');

        $this->actingAs($admin)
            ->post('/admin/rental-documents/'.$document->getKey().'/verify')
            ->assertSessionHasNoErrors();

        $verified = $document->fresh();
        $this->assertSame('verified', $verified->status);
        $this->assertSame($admin->getKey(), $verified->verified_by);
        $this->assertNotNull($verified->verified_at);
    }

    public function test_self_drive_requires_all_three_verified_documents(): void
    {
        [$agency, $vehicle] = $this->agencyWithVehicle();
        $customer = User::factory()->create(['status' => 'active']);
        $create = app(CreateRentalBooking::class);
        $arguments = [
            $customer, $agency, $vehicle, RentalType::SelfDrive,
            now()->addDay()->setTime(8, 0), now()->addDays(2)->setTime(8, 0),
        ];

        try {
            $create->execute(...$arguments, idempotencyKey: 'self-drive-1');
            $this->fail('Lepas kunci tanpa dokumen seharusnya ditolak.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('KTP', $exception->getMessage());
        }

        foreach (RentalDocument::TYPES as $type) {
            RentalDocument::query()->create([
                'user_id' => $customer->getKey(),
                'type' => $type,
                'file_path' => 'rental-documents/'.$type.'.jpg',
                'status' => 'verified',
                'verified_at' => now(),
            ]);
        }

        $rental = $create->execute(...$arguments, idempotencyKey: 'self-drive-2');

        $this->assertSame('self_drive', $rental->rental_type);
        $this->assertNull($rental->driver_id);
    }

    public function test_agency_can_open_documents_only_while_the_rental_runs(): void
    {
        [$agency, $vehicle, $driver] = $this->agencyWithVehicle(withDriver: true);
        $customer = User::factory()->create(['status' => 'active']);
        $owner = User::query()->findOrFail($agency->owner_user_id);

        $document = RentalDocument::query()->create([
            'user_id' => $customer->getKey(),
            'type' => 'sim',
            'file_path' => 'rental-documents/sim.jpg',
            'status' => 'verified',
            'verified_at' => now(),
        ]);
        Storage::disk('local')->put($document->file_path, 'berkas');

        $rental = app(CreateRentalBooking::class)->execute(
            $customer, $agency, $vehicle, RentalType::WithDriver,
            now()->addDay()->setTime(8, 0), now()->addDays(3)->setTime(8, 0),
            'doc-access-1', $driver,
        );

        // Sebelum sewa berjalan, agency belum berhak melihat dokumen.
        $this->actingAs($owner)
            ->get('/mad-trans/rental-documents/'.$document->getKey().'/file')
            ->assertForbidden();

        $this->actingAs($owner)
            ->post('/mad-trans/agency/'.$agency->getKey().'/rentals/'.$rental->getKey().'/paid')
            ->assertSessionHasNoErrors();
        $this->actingAs($owner)
            ->post('/mad-trans/agency/'.$agency->getKey().'/rentals/'.$rental->getKey().'/start')
            ->assertSessionHasNoErrors();

        $this->actingAs($owner)
            ->get('/mad-trans/rental-documents/'.$document->getKey().'/file')
            ->assertOk();
        $this->actingAs($owner)
            ->get('/mad-trans/agency/'.$agency->getKey().'/rentals/'.$rental->getKey().'/documents')
            ->assertOk()
            ->assertSee('SIM');

        $this->actingAs($owner)
            ->post('/mad-trans/agency/'.$agency->getKey().'/rentals/'.$rental->getKey().'/return')
            ->assertSessionHasNoErrors();
        $this->actingAs($owner)
            ->post('/mad-trans/agency/'.$agency->getKey().'/rentals/'.$rental->getKey().'/complete')
            ->assertSessionHasNoErrors();

        $completed = $rental->fresh();
        $this->assertSame('completed', $completed->status);
        $this->assertNotNull($completed->documents_revoked_at);

        $this->actingAs($owner)
            ->get('/mad-trans/rental-documents/'.$document->getKey().'/file')
            ->assertForbidden();
    }

    private function platformAdmin(): User
    {
        $admin = User::factory()->accountType(AccountType::Admin)->create(['status' => 'active']);
        PlatformUserRole::query()->create(['user_id' => $admin->getKey(), 'role' => 'admin']);

        return $admin;
    }

    private function agencyWithVehicle(bool $withDriver = false): array
    {
        $owner = User::factory()->accountType(AccountType::AgencyOwner)->create(['status' => 'active']);
        $agency = Business::query()->create([
            'type' => 'agency',
            'name' => 'Rental Dokumen',
            'code' => 'GM-AGENCY-DOC1',
            'owner_user_id' => $owner->getKey(),
            'status' => 'active',
        ]);
        $agency->members()->attach($owner, ['role' => 'pemilik', 'status' => 'active', 'joined_at' => now()]);

        $vehicle = Vehicle::query()->create([
            'business_id' => $agency->getKey(),
            'name' => 'Avanza Dokumen',
            'plate_number' => 'B 1357 DOC',
            'seat_layout' => ['A1', 'A2'],
            'capacity' => 2,
            'rental_daily_price' => '300000.00',
            'rental_driver_daily_fee' => '100000.00',
            'status' => 'active',
        ]);

        $driver = null;

        if ($withDriver) {
            $driverUser = User::factory()->create(['status' => 'active']);
            $agency->members()->attach($driverUser, ['role' => 'driver', 'status' => 'active', 'joined_at' => now()]);
            $driver = BusinessMember::query()
                ->where('business_id', $agency->getKey())
                ->where('user_id', $driverUser->getKey())
                ->firstOrFail();

            $this->fundCollateral($agency, 50000);
        }

        return [$agency, $vehicle, $driver];
    }

    private function fundCollateral(Business $agency, int $amount): void
    {
        app(LedgerAccountSeeder::class)->ensureBusinessAccounts($agency);
        app(PostLedgerTransaction::class)->execute(new PostLedgerTransactionData(
            category: LedgerCategory::CollateralTopup,
            idempotencyKey: 'test:doc-collateral:'.$agency->getKey(),
            entries: [
                new LedgerEntryInput('PLATFORM.CASH', LedgerDirection::Debit, (string) $amount),
                new LedgerEntryInput(
                    'BUSINESS.COLLATERAL:'.$agency->getKey(),
                    LedgerDirection::Credit,
                    (string) $amount,
                ),
            ],
            referenceType: 'test',
            referenceId: (int) $agency->getKey(),
            description: 'Fixture jaminan dokumen',
        ));
    }
}
