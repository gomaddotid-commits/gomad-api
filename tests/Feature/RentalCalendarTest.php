<?php

namespace Tests\Feature;

use App\Core\Access\Infrastructure\AccessControlSeeder;
use App\Core\Ledger\Application\PostLedgerTransaction;
use App\Core\Ledger\Domain\LedgerCategory;
use App\Core\Ledger\Domain\LedgerDirection;
use App\Core\Ledger\Domain\LedgerEntryInput;
use App\Core\Ledger\Domain\PostLedgerTransactionData;
use App\Core\Ledger\Infrastructure\LedgerAccountSeeder;
use App\Core\Rental\Application\CreateRentalBooking;
use App\Core\Rental\Application\VehicleAvailability;
use App\Core\Rental\Domain\RentalType;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RentalCalendarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.rental.service_fee' => 10000,
            'services.rental.platform_fee_basis_points' => 300,
            'services.rental.ppn_basis_points' => 1100,
        ]);
        $this->seed(AccessControlSeeder::class);
        app(LedgerAccountSeeder::class)->run();
    }

    public function test_calendar_marks_days_taken_by_a_rental(): void
    {
        [$agency, $vehicle, $driver] = $this->agencyWithVehicle();
        $customer = User::factory()->create(['status' => 'active']);
        $owner = User::query()->findOrFail($agency->owner_user_id);

        $start = now()->addDays(5)->setTime(8, 0);

        app(CreateRentalBooking::class)->execute(
            $customer,
            $agency,
            $vehicle,
            RentalType::WithDriver,
            $start,
            $start->copy()->addDays(2),
            'calendar-1',
            $driver,
        );

        $taken = app(VehicleAvailability::class)->month($vehicle, $start->copy()->startOfMonth())['days'];
        $this->assertArrayHasKey($start->toDateString(), $taken);
        $this->assertArrayHasKey($start->copy()->addDay()->toDateString(), $taken);

        $response = $this->actingAs($owner)->get(
            '/mad-trans/rental/'.$agency->getKey().'/vehicles/'.$vehicle->getKey().'/calendar'
        );

        $response->assertOk();
        $response->assertSee('Kalender armada');
        $response->assertSee('hari terpakai');
    }

    public function test_calendar_rejects_a_vehicle_from_another_business(): void
    {
        [$agency] = $this->agencyWithVehicle();
        $owner = User::query()->findOrFail($agency->owner_user_id);

        $otherAgency = Business::query()->create([
            'type' => 'agency',
            'name' => 'Agency Lain Kalender',
            'code' => 'GM-AGENCY-CAL2',
            'owner_user_id' => $owner->getKey(),
            'status' => 'active',
        ]);
        $otherVehicle = Vehicle::query()->create([
            'business_id' => $otherAgency->getKey(),
            'name' => 'Avanza Lain',
            'plate_number' => 'B 2468 CAL',
            'seat_layout' => ['A1'],
            'capacity' => 1,
            'rental_daily_price' => '200000.00',
            'status' => 'active',
        ]);

        $this->actingAs($owner)
            ->get('/mad-trans/rental/'.$agency->getKey().'/vehicles/'.$otherVehicle->getKey().'/calendar')
            ->assertNotFound();
    }

    private function agencyWithVehicle(): array
    {
        $owner = User::factory()->create(['status' => 'active']);
        $agency = Business::query()->create([
            'type' => 'agency',
            'name' => 'Rental Kalender',
            'code' => 'GM-AGENCY-CAL1',
            'owner_user_id' => $owner->getKey(),
            'status' => 'active',
        ]);
        $agency->members()->attach($owner, ['role' => 'pemilik', 'status' => 'active', 'joined_at' => now()]);

        $vehicle = Vehicle::query()->create([
            'business_id' => $agency->getKey(),
            'name' => 'Avanza Kalender',
            'plate_number' => 'B 1234 CAL',
            'seat_layout' => ['A1', 'A2'],
            'capacity' => 2,
            'rental_daily_price' => '300000.00',
            'rental_driver_daily_fee' => '100000.00',
            'status' => 'active',
        ]);

        $driverUser = User::factory()->create(['status' => 'active']);
        $agency->members()->attach($driverUser, ['role' => 'driver', 'status' => 'active', 'joined_at' => now()]);
        $driver = BusinessMember::query()
            ->where('business_id', $agency->getKey())
            ->where('user_id', $driverUser->getKey())
            ->firstOrFail();

        app(LedgerAccountSeeder::class)->ensureBusinessAccounts($agency);
        app(PostLedgerTransaction::class)->execute(new PostLedgerTransactionData(
            category: LedgerCategory::CollateralTopup,
            idempotencyKey: 'test:calendar-collateral:'.$agency->getKey(),
            entries: [
                new LedgerEntryInput('PLATFORM.CASH', LedgerDirection::Debit, '50000'),
                new LedgerEntryInput('BUSINESS.COLLATERAL:'.$agency->getKey(), LedgerDirection::Credit, '50000'),
            ],
            referenceType: 'test',
            referenceId: (int) $agency->getKey(),
            description: 'Fixture jaminan kalender',
        ));

        return [$agency, $vehicle, $driver];
    }
}
