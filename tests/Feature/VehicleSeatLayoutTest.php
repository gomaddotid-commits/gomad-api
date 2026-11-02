<?php

namespace Tests\Feature;

use App\Core\Access\Infrastructure\AccessControlSeeder;
use App\Core\Identity\Domain\AccountType;
use App\Core\MadTrans\Domain\SeatLayout;
use App\Models\Business;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Formasi kursi armada.
 *
 * Owner memilih jumlah kursi per baris (mis. 2-3-3) dan menandai kursi supir.
 * Kode kursi dibentuk dari formasi itu, kapasitas = jumlah kursi yang dijual,
 * dan kursi supir tidak pernah ikut menjadi kursi yang bisa dipesan.
 */
class VehicleSeatLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_owner_sets_formation_and_driver_seat(): void
    {
        [$owner, $agency] = $this->agency();

        $this->actingAs($owner)
            ->post(route('mad-trans.agency.vehicles.store', $agency), [
                'name' => 'Hiace Premio',
                'plate_number' => 'B 1234 SEA',
                'seat_rows' => [2, 3, 3, 0, 0, 0],
                'has_driver_seat' => '1',
            ])
            ->assertRedirect(route('mad-trans.agency.show', $agency));

        $vehicle = Vehicle::query()->where('plate_number', 'B 1234 SEA')->firstOrFail();

        $this->assertSame(['A1', 'A2', 'B1', 'B2', 'B3', 'C1', 'C2', 'C3'], $vehicle->seat_layout);
        $this->assertSame(8, (int) $vehicle->capacity);
        $this->assertTrue($vehicle->has_driver_seat);
        $this->assertSame('2-3-3', SeatLayout::summary($vehicle->seat_layout));
    }

    public function test_owner_can_leave_the_driver_seat_out(): void
    {
        [$owner, $agency] = $this->agency();

        $this->actingAs($owner)
            ->post(route('mad-trans.agency.vehicles.store', $agency), [
                'name' => 'Minibus',
                'plate_number' => 'B 5678 SEA',
                'seat_rows' => [2, 2, 2, 0, 0, 0],
            ])
            ->assertRedirect();

        $vehicle = Vehicle::query()->where('plate_number', 'B 5678 SEA')->firstOrFail();

        $this->assertFalse($vehicle->has_driver_seat);
        $this->assertSame('2-2-2', SeatLayout::summary($vehicle->seat_layout));
        $this->assertCount(6, $vehicle->seat_layout);
    }

    public function test_empty_formation_is_rejected(): void
    {
        [$owner, $agency] = $this->agency();

        $this->actingAs($owner)
            ->post(route('mad-trans.agency.vehicles.store', $agency), [
                'name' => 'Kosong',
                'plate_number' => 'B 0000 SEA',
                'seat_rows' => [0, 0, 0, 0, 0, 0],
            ])
            ->assertSessionHasErrors('seat_rows');

        $this->assertSame(0, Vehicle::query()->count());
    }

    public function test_formation_helper_builds_rows_and_summary(): void
    {
        $seats = SeatLayout::fromRows([2, 0, 3]);

        // Baris kosong dilewati, label baris tetap mengikuti posisinya.
        $this->assertSame(['A1', 'A2', 'C1', 'C2', 'C3'], $seats);
        $this->assertSame(['A' => 2, 'C' => 3], SeatLayout::rows($seats));
        $this->assertSame('2-3', SeatLayout::summary($seats));
    }

    public function test_formation_helper_rejects_out_of_range_row(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SeatLayout::fromRows([5]);
    }

    /**
     * @return array{0: User, 1: Business}
     */
    private function agency(): array
    {
        $owner = User::factory()->accountType(AccountType::AgencyOwner)->create(['status' => 'active']);

        $agency = Business::query()->create([
            'type' => 'agency',
            'name' => 'Agency Kursi',
            'code' => 'GM-AGENCY-SEAT1',
            'owner_user_id' => $owner->getKey(),
            'status' => 'active',
        ]);

        $agency->members()->attach($owner->getKey(), [
            'role' => 'pemilik',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return [$owner, $agency];
    }
}
