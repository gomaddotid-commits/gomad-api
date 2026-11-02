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
use App\Core\MadTrans\Application\CreateTravelBooking;
use App\Core\MadTrans\Application\TransferTravelBooking;
use App\Core\Payment\Domain\PaymentConflict;
use App\Models\BookingPassenger;
use App\Models\Business;
use App\Models\LedgerTransaction;
use App\Models\RoutePricing;
use App\Models\RouteStop;
use App\Models\TravelRoute;
use App\Models\TravelSchedule;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MadTransTransferTest extends TestCase
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

    public function test_agency_moves_a_paid_booking_to_another_schedule_without_touching_the_ledger(): void
    {
        [$agency, $vehicle, $stops, $first, $second] = $this->twoSchedules();
        $this->fundCollateral($agency, 50000);
        $customer = User::factory()->create(['status' => 'active']);
        $owner = User::query()->findOrFail($agency->owner_user_id);

        $booking = app(CreateTravelBooking::class)->execute(
            $customer,
            $first,
            $stops[0],
            $stops[1],
            [['name' => 'Penumpang Pindah', 'phone' => null, 'seat_number' => 'A1']],
            'cod',
            'transfer-booking-1',
        );

        $ledgerBefore = LedgerTransaction::query()->count();

        $transfer = app(TransferTravelBooking::class)->execute($owner, $booking, $second);

        $this->assertSame('approved', $transfer->status);
        $this->assertSame('0.00', $transfer->fee);
        $this->assertSame($first->getKey(), $transfer->from_schedule_id);
        $this->assertSame($second->getKey(), $transfer->to_schedule_id);

        $this->assertSame($second->getKey(), $booking->fresh()->schedule_id);
        $passenger = BookingPassenger::query()->where('booking_id', $booking->getKey())->firstOrFail();
        $this->assertSame($second->getKey(), $passenger->schedule_id);

        // Kursi ikut berpindah: bebas di jadwal asal, terisi di jadwal tujuan.
        $this->assertSame(0, BookingPassenger::query()
            ->where('schedule_id', $first->getKey())->whereNotNull('seat_number')->count());
        $this->assertSame(1, BookingPassenger::query()
            ->where('schedule_id', $second->getKey())->where('seat_number', 'A1')->count());

        $this->assertSame($ledgerBefore, LedgerTransaction::query()->count());

        $this->actingAs($owner)
            ->get('/mad-trans/agency/'.$agency->getKey().'/schedules/'.$second->getKey())
            ->assertOk()
            ->assertSee($booking->code);
    }

    public function test_transfer_is_rejected_when_the_target_price_differs(): void
    {
        [$agency, , $stops, $first, $second] = $this->twoSchedules();
        $this->fundCollateral($agency, 50000);
        $customer = User::factory()->create(['status' => 'active']);
        $owner = User::query()->findOrFail($agency->owner_user_id);

        $booking = app(CreateTravelBooking::class)->execute(
            $customer,
            $first,
            $stops[0],
            $stops[1],
            [['name' => 'Penumpang Mahal', 'phone' => null, 'seat_number' => 'A1']],
            'cod',
            'transfer-price-1',
        );

        RoutePricing::query()
            ->where('route_id', $second->route_id)
            ->update(['price' => '150000.00']);

        $this->expectException(\InvalidArgumentException::class);

        app(TransferTravelBooking::class)->execute($owner, $booking, $second->fresh());
    }

    public function test_transfer_is_rejected_when_the_target_seat_is_taken(): void
    {
        [$agency, , $stops, $first, $second] = $this->twoSchedules();
        $this->fundCollateral($agency, 50000);
        $customer = User::factory()->create(['status' => 'active']);
        $owner = User::query()->findOrFail($agency->owner_user_id);
        $create = app(CreateTravelBooking::class);

        $moving = $create->execute(
            $customer, $first, $stops[0], $stops[1],
            [['name' => 'Penumpang Pindah', 'phone' => null, 'seat_number' => 'A1']],
            'cod', 'transfer-conflict-1',
        );

        $create->execute(
            $customer, $second, $stops[0], $stops[1],
            [['name' => 'Penumpang Tetap', 'phone' => null, 'seat_number' => 'A1']],
            'cod', 'transfer-conflict-2',
        );

        $this->expectException(PaymentConflict::class);

        app(TransferTravelBooking::class)->execute($owner, $moving, $second);
    }

    private function twoSchedules(): array
    {
        $owner = User::factory()->accountType(AccountType::AgencyOwner)->create(['status' => 'active']);
        $agency = Business::query()->create([
            'type' => 'agency',
            'name' => 'Travel Transfer',
            'code' => 'GM-AGENCY-TRF1',
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
            'route_id' => $route->getKey(), 'stop_order' => 1,
            'city_name' => 'Sumenep', 'name' => 'Terminal Sumenep',
        ]);
        $destination = RouteStop::query()->create([
            'route_id' => $route->getKey(), 'stop_order' => 2,
            'city_name' => 'Surabaya', 'name' => 'Terminal Bungurasih',
        ]);
        RoutePricing::query()->create([
            'route_id' => $route->getKey(),
            'origin_stop_id' => $origin->getKey(),
            'destination_stop_id' => $destination->getKey(),
            'price' => '100000.00',
            'is_active' => true,
        ]);
        $vehicle = Vehicle::query()->create([
            'business_id' => $agency->getKey(),
            'name' => 'Elf Transfer',
            'plate_number' => 'M 6666 TRF',
            'seat_layout' => ['A1', 'A2'],
            'capacity' => 2,
            'status' => 'active',
        ]);

        $makeSchedule = function (int $offset, string $time) use ($agency, $vehicle, $route, $origin, $destination, $owner): TravelSchedule {
            $schedule = TravelSchedule::query()->create([
                'business_id' => $agency->getKey(),
                'vehicle_id' => $vehicle->getKey(),
                'route_id' => $route->getKey(),
                'departure_date' => now()->addDays($offset)->toDateString(),
                'departure_time' => $time,
                'travel_class' => 'regular',
                'price_per_seat' => '100000.00',
                'status' => 'published',
                'proposed_by' => $owner->getKey(),
                'allow_cod' => true,
                'payment_methods' => ['midtrans', 'cod'],
            ]);
            $schedule->stops()->createMany([
                ['route_stop_id' => $origin->getKey(), 'stop_order' => 1],
                ['route_stop_id' => $destination->getKey(), 'stop_order' => 2],
            ]);

            return $schedule;
        };

        return [
            $agency,
            $vehicle,
            [$origin->getKey(), $destination->getKey()],
            $makeSchedule(1, '08:00:00'),
            $makeSchedule(2, '08:00:00'),
        ];
    }

    private function fundCollateral(Business $agency, int $amount): void
    {
        app(LedgerAccountSeeder::class)->ensureBusinessAccounts($agency);
        app(PostLedgerTransaction::class)->execute(new PostLedgerTransactionData(
            category: LedgerCategory::CollateralTopup,
            idempotencyKey: 'test:transfer-collateral:'.$agency->getKey(),
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
            description: 'Fixture jaminan transfer',
        ));
    }
}
